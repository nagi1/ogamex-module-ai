<?php

namespace Modules\AI\Domain\Decision;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Doctrine\ArchetypeDoctrine;
use Modules\AI\Domain\Persona\SavingsGoal;
use Modules\AI\Enums\AiStockpileStrategy;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\ResearchQueueService;
use Symfony\Component\Yaml\Yaml;

/**
 * Answers one question about the account's own economy: is there a building or a technology it can
 * legally queue now, and on which planet?
 *
 * Two things want a building. The chain wants the facility a later capability cannot exist without
 * -- an account with no research lab can never research, and one with no shipyard can never own a
 * ship -- and the economy wants the upgrade that repays itself fastest, or the storage that is about
 * to overflow. The plan runs in two passes across the account's planets: first a warehouse that is
 * about to overflow anywhere (it stops that planet producing, so it outranks every routine step on
 * every other planet), then the routine economy planet by planet -- the energy a planet needs before
 * it throttles, the chain's facilities, then the fastest-paying mine. The cross-planet storage pass
 * is what keeps a full warehouse on one colony from waiting behind a routine mine on the homeworld.
 *
 * Both are only suggestions. Every gate is the host's own -- planet type, free queue space,
 * requirements met against what is built *and* queued, and a price the planet can pay, which is the
 * same set the building page shows a human -- so the module never restates an OGame rule. A target
 * the host rejects is not an error but a fall-through: the planner tries the next one, which is what
 * keeps a single impossible favourite from costing the account its whole build capability.
 *
 * Affordability is a real gate rather than a nicety: `BuildingQueueService::start()` cancels a queue
 * item it cannot pay for, so publishing `build` while short of resources spends a queue slot and
 * reports nothing at all.
 */
class QueueableBuildingPlanner
{
    /** The lab is one queue for the account, so its step has one slot beside the per-planet building steps. */
    private const LAB_STEP = 'lab';

    /** The pass that stands the facilities a bare planet's wall waits on: it is a fix, not a choice. */
    private const WALL_STEP = 'wall';

    /** Candidates kept per choice; with the wait option the policy sees at most 32 rows. */
    private const CHOICE_CANDIDATES = 31;

    /** The behaviour file that says how many fields a planet keeps for the stations only it can hold. */
    private const FIELD_BEHAVIOR_FILE = '/resources/behavior/planet-fields.yaml';

    /** The reserve floor, read once per process (`resources/behavior/planet-fields.yaml`). */
    private static ?int $fieldReserve = null;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private FacilityChain $facilityChain,
        private EnergyCapacity $energyCapacity,
        private EconomyUpgrades $economyUpgrades,
        private ReserveFloor $reserveFloor,
        private BuildingQueueService $buildingQueueService,
        private ResearchQueueService $researchQueueService,
    ) {
    }

    public function plan(int $playerId, ?PlayerService $player = null): QueueableBuilding|QueueableResearch|null
    {
        return $this->steps($playerId, $player, 1)[0] ?? null;
    }

    /**
     * Every queue a player would fill in one login: at most one building per planet, because each planet
     * pays from its own stock and has its own build queue, and at most one technology, because the
     * lab is one queue for the account. The order is plan()'s, so the first step is the one plan()
     * returns.
     *
     * @return list<QueueableBuilding|QueueableResearch>
     */
    public function steps(int $playerId, ?PlayerService $player = null, int $limit = PHP_INT_MAX): array
    {
        // An account the module does not manage has no policy to apply, so it gets no capability.
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return [];
        }

        // The module owns profile rows; the host owns accounts. A profile can outlive its account or
        // be written by a fixture, and loading an account the host does not have throws -- so the
        // precondition is asked here rather than discovered in the host service.
        if (!User::query()->whereKey($playerId)->exists()) {
            return [];
        }

        // Refresh every planet's live balance once: the candidate pass reads stored amounts and
        // energy, and a balance read stale is exactly the balance the queue later cancels on. The
        // refresh stays in memory -- the observation path must not write.
        $planets = ($player ?? $this->playerServiceFactory->make($playerId, true))->planets->all();
        foreach ($planets as $planet) {
            $planet->updateResources(false);
            $planet->updateResourceProductionStats(false);
            $planet->updateResourceStorageStats(false);
        }

        $passes = $this->passes($profile);

        // What every planet is holding back, once per session: reading it walks the same candidate
        // lists the passes do, so asking again inside each pass would repeat a planet's whole economy,
        // and a planet's hold is its own.
        // It is also what keeps this list honest: the cohort reads "buildable" from exactly these
        // steps, so a planet whose pile the goal reserves must not be offered here -- otherwise the
        // read-out says buildable while the account rightly saves, and the invariant flags a saver
        // for behaving as designed.
        $goals = [];
        foreach ($planets as $planet) {
            $goals[$planet->getPlanetId()] = $this->savingsGoal($planet, $profile);
        }

        $steps = [];
        foreach ($passes as $pass => $candidates) {
            foreach ($planets as $planet) {
                if (count($steps) >= $limit) {
                    return array_values($steps);
                }

                $steps = $this->withStep($steps, $planet, $profile, $candidates, $goals[$planet->getPlanetId()], $pass === self::WALL_STEP);
            }
        }

        return array_values($steps);
    }

    /**
     * Every candidate the passes offer for each free queue, in pass order, with what the host and the
     * planner's own taste say about it: the material a choice policy ranks, and the step the planner
     * chose as the answer to imitate. Read only when a choice policy or the choice recorder is on, so
     * the plan itself never pays for it.
     *
     * @param list<QueueableBuilding|QueueableResearch> $chosen what steps() returned for this player
     * @return list<array{planet: PlanetService, research: bool, candidates: list<array{candidate: BuildCandidate, pass: string, legal: bool, teacherOk: bool, spendable: bool}>, teacher: ?int}>
     */
    public function choiceSets(int $playerId, array $chosen, PlayerService $player): array
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return [];
        }

        $passes = $this->passes($profile);
        $buildingFor = [];
        $research = null;
        foreach ($chosen as $step) {
            if ($step instanceof QueueableResearch) {
                $research = $step;
                continue;
            }
            $buildingFor[$step->planetId] = $step->buildingId;
        }

        $sets = [];
        $lab = null;
        foreach ($player->planets->all() as $planet) {
            $goal = $this->savingsGoal($planet, $profile);
            $offered = $this->offered($planet, $passes);

            if (!$this->buildingQueueService->retrieveQueue($planet)->isQueueFull()) {
                $sets[] = $this->choiceSet($planet, false, $offered, $goal, $buildingFor[$planet->getPlanetId()] ?? null);
            }

            if ($lab !== null || $this->researchQueueService->retrieveQueue($planet)->isQueueFull()) {
                continue;
            }

            // The lab is account-wide; the planet that answers is the one the planner used, else the
            // first one that can research anything at all.
            $set = $this->choiceSet($planet, true, $offered, $goal, $research?->planetId === $planet->getPlanetId() ? $research->researchId : null);
            $lab = $research?->planetId === $planet->getPlanetId() || in_array(true, array_column($set['candidates'], 'legal'), true) ? $set : null;
        }

        return $lab === null ? $sets : [...$sets, $lab];
    }

    /**
     * Each object once, in the order the passes offer it, tagged with the first pass that offered it.
     *
     * @param array<string, callable(PlanetService): list<BuildCandidate>> $passes
     * @return list<array{candidate: BuildCandidate, pass: string}>
     */
    private function offered(PlanetService $planet, array $passes): array
    {
        $seen = [];
        $offered = [];
        foreach ($passes as $name => $pass) {
            foreach ($pass($planet) as $candidate) {
                if (isset($seen[$candidate->buildingId])) {
                    continue;
                }
                $seen[$candidate->buildingId] = true;
                $offered[] = ['candidate' => $candidate, 'pass' => $name];
            }
        }

        return $offered;
    }

    /**
     * @param list<array{candidate: BuildCandidate, pass: string}> $offered
     * @return array{planet: PlanetService, research: bool, candidates: list<array{candidate: BuildCandidate, pass: string, legal: bool, teacherOk: bool, spendable: bool}>, teacher: ?int}
     */
    private function choiceSet(PlanetService $planet, bool $research, array $offered, ?SavingsGoal $goal, ?int $teacherObjectId): array
    {
        $rows = [];
        $teacher = null;
        foreach ($offered as ['candidate' => $candidate, 'pass' => $pass]) {
            if ((ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research) !== $research) {
                continue;
            }
            // A bounded list; the planner's own answer always stays in it.
            if (count($rows) >= self::CHOICE_CANDIDATES && $candidate->buildingId !== $teacherObjectId) {
                continue;
            }

            $rows[] = [
                'candidate' => $candidate,
                'pass' => $pass,
                // What the host would accept now, without the planner's reserve: a policy may spend into it.
                'legal' => $research ? $this->queueableResearch($planet, $candidate, null) !== null : $this->refusal($planet, $candidate, null) === null,
                'teacherOk' => $research ? $this->queueableResearch($planet, $candidate) !== null : $this->refusal($planet, $candidate) === null,
                'spendable' => $this->canSpendFor($planet, $pass === self::WALL_STEP ? null : $goal, $candidate),
            ];
            $teacher = $candidate->buildingId === $teacherObjectId ? count($rows) - 1 : $teacher;
        }

        return ['planet' => $planet, 'research' => $research, 'candidates' => $rows, 'teacher' => $teacher];
    }

    /**
     * The candidate lists in the order the planner tries them. A warehouse about to overflow stops
     * that planet producing, so its pass runs across every planet before any routine step; a full
     * warehouse (E7) is a spend signal and comes next; then the routine economy: energy before it
     * throttles, the chain's facilities, the fastest-paying mine. Public so a read-out explains a
     * planet with the same lists the decision used.
     *
     * @return array<string, callable(PlanetService): list<BuildCandidate>>
     */
    public function passes(AiProfile $profile): array
    {
        return [
            // A planet holding no defence while a sibling already stands a wall is past the
            // account's opening with a sibling walled everywhere else: the facilities the wall
            // itself waits on come before the economy, so the planet is not left naked behind a
            // stock it is busy spending (QUAL-003). The step is the same shape as a wall order: a
            // saving the economy is holding back does not veto it, because a planet with no wall is
            // the state the account is fixing, not a step it chooses between (QUAL-003).
            'wall' => fn (PlanetService $planet): array => $this->facilityChain->wallPending($planet),
            // The archetype's written opening and research path (architecture step 4): the build list a guide
            // gives, in order, before the payback rules below take over. An object the host lacks is skipped.
            'doctrine' => fn (PlanetService $planet): array => [
                ...app(ArchetypeDoctrine::class)->openingStep($profile->archetype, $planet),
                ...($planet->getPlayer() !== null ? app(ArchetypeDoctrine::class)->researchStep($profile->archetype, $planet->getPlayer()) : []),
            ],
            'storage' => fn (PlanetService $planet): array => $this->withFieldReserve($planet, $profile, $this->economyUpgrades->storage($planet, $profile)),
            'surplus' => function (PlanetService $planet) use ($profile): array {
                $spend = $this->economyUpgrades->spendSurplus($planet, $profile);
                // The last fields go to what adds fields before a full store spends them on another mine level.
                $fields = $spend === [] ? [] : $this->fieldCapacity($planet);
                if ($fields !== []) {
                    return [...$fields, ...$this->withFieldsFor($planet, $spend, $this->fieldReserve($planet))];
                }
                if ($spend === [] || !$this->minesOutgrownStations($planet, $this->economyUpgrades->production($planet, $profile))) {
                    return $this->withFieldReserve($planet, $profile, $spend);
                }

                // The pile is spent on the station project this planet has outgrown before another
                // mine level is bought: the station is the purchase the account has decided on, and
                // the dearest thing a settled planet buys is the station it waits on. The mine below
                // is still the step whenever the station cannot be paid for yet, so the surplus is
                // never left to overflow. The capacity the host throttles the planet without comes
                // first, exactly as it does in the routine pass, so a short planet still buys power.
                return [
                    ...$this->withFieldsFor($planet, $this->energyCapacity->pending($planet), $this->fieldReserve($planet)),
                    ...$this->facilityChain->stationPending($planet),
                    ...$this->withFieldReserve($planet, $profile, $spend),
                ];
            },
            'routine' => function (PlanetService $planet) use ($profile): array {
                // The mine that repays today is an immediate need, so it also gates the station project
                // below: a planet whose mines still repay is not settled, and it keeps mining.
                $paying = $this->economyUpgrades->production($planet, $profile);
                $stations = $this->minesOutgrownStations($planet, $paying);
                // A planet down to the fields the station project still needs keeps them for it (COVER-
                // OBJECTS-001): the host refuses every field-consuming building once a planet's fields
                // are used up, the object that adds fields included, so the last fields decide whether
                // the account can ever hold what is behind them. Only the economy's own steps are held
                // back this way; the facilities it is keeping them for are not.
                $reserve = $stations ? $this->fieldReserve($planet) : 0;

                return [
                    ...$this->fieldCapacity($planet),
                    ...$this->withFieldsFor($planet, $this->energyCapacity->pending($planet), $reserve),
                    // A station the host's catalogue offers and this planet does not hold is a goal with a
                    // list of its own (FacilityChain::stationPending). The chain's one ambition is its next
                    // war hull, and the station the account could already hold is skipped as "producible",
                    // so the facilities only a station waits on were never asked for and the deepest
                    // stations stayed unowned (COVER-OBJECTS-001). It comes after the capacity the host
                    // throttles the planet without, and before the war hull, which is a goal on every
                    // login of every account that owns a ship and would otherwise never give it a turn.
                    ...($stations ? $this->facilityChain->stationPending($planet) : []),
                    ...$this->facilityChain->pending($planet),
                    ...$this->withFieldsFor($planet, $this->economyUpgrades->storageForPrice($planet, $profile), $reserve),
                    ...$this->withFieldsFor($planet, $paying, $reserve),
                ];
            },
            // A planet sitting on six times the price of a facility it does not own buys it -- but only
            // once nothing urgent is left, which is what that pass says it is for: the capacity the host
            // throttles the planet without, the prerequisites the rest of the game is gated behind and the
            // mine that repays first all outrank a facility bought for its own sake (COVER-*). Placed
            // ahead of them it bought a robotics factory on a planet that could not yet cover its mines
            // (measured: AiCapabilityPublicationTest, EnergyCapacityTest).
            'ambition' => fn (PlanetService $planet): array => $this->economyUpgrades->ambitions($planet),
        ];
    }

    /**
     * The fields this planet keeps for the station project, levels included: at least the pair the range
     * cannot do without, and as many as its own unmet prerequisites still ask for.
     *
     * The count is the host's, not this module's: every station the catalogue offers the planet, and
     * every level of the graph its prerequisites reach, is a field the planet will have to find. Reading
     * it is what keeps the reserve honest -- a planet that owes the range five fields keeps five, so a
     * prerequisite level cannot eat the field the station behind it was kept for (COVER-OBJECTS-001).
     * The floor is behaviour data (`resources/behavior/planet-fields.yaml`), not arithmetic.
     */
    private function fieldReserve(PlanetService $planet): int
    {
        return max($this->stationReserveFields(), $this->facilityChain->stationFieldBudget($planet));
    }

    /** The floor a planet keeps for the station project, from the behaviour file. */
    private function stationReserveFields(): int
    {
        if (self::$fieldReserve === null) {
            $file = Yaml::parseFile(dirname(__DIR__, 3) . self::FIELD_BEHAVIOR_FILE);
            self::$fieldReserve = (int) (is_array($file) ? ($file['station_reserve_fields'] ?? 0) : 0);
        }

        return self::$fieldReserve;
    }

    /**
     * The economy's own steps with the fields the station project is owed taken out of them.
     *
     * @param list<BuildCandidate> $candidates
     * @return list<BuildCandidate>
     */
    private function withFieldReserve(PlanetService $planet, AiProfile $profile, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $reserve = $this->minesOutgrownStations($planet, $this->economyUpgrades->production($planet, $profile))
            ? $this->fieldReserve($planet)
            : 0;

        return $this->withFieldsFor($planet, $candidates, $reserve);
    }

    /**
     * Drop the steps that would spend one of the fields this planet is keeping for the station project.
     *
     * A planet owns a finite number of fields and the host refuses every field-consuming building once
     * they are used up, so the account that spends the last of them on another mine level never reaches
     * the object that adds fields -- however much it mines. A player keeps them for the station range
     * for exactly that reason. Only the economy's steps are answered this way: the facilities (the
     * station project, the chain, the wall) are what the fields are being kept for and are never
     * withheld, and a technology is built in the lab rather than on a field, so it is never dropped.
     *
     * @param list<BuildCandidate> $candidates
     * @return list<BuildCandidate>
     */
    private function withFieldsFor(PlanetService $planet, array $candidates, int $reserve): array
    {
        if ($reserve <= 0 || $this->freeFields($planet) > $reserve) {
            return $candidates;
        }

        return array_values(array_filter($candidates, fn (BuildCandidate $candidate): bool => !$this->consumesAField($candidate)));
    }

    /**
     * The next level of whatever adds fields, once the planet is down to the fields it keeps: the station project only
     * asks for the first level of each station, so a planet that filled its fields after that stopped building for good.
     * Which object adds fields is the host's own field formula asked with one more level, never a name.
     *
     * @return list<BuildCandidate>
     */
    private function fieldCapacity(PlanetService $planet): array
    {
        // A moon's fields are the moon block's answer (RV-008/RV-009), as its stations are.
        if ($planet->getPlanetType() === PlanetType::Moon || $this->freeFields($planet) > $this->fieldReserve($planet)) {
            return [];
        }

        $candidates = [];
        foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()] as $object) {
            $level = $planet->getObjectLevel($object->machine_name);
            if (!ObjectService::objectValidPlanetType($object->machine_name, $planet)
                || !ObjectService::objectRequirementsMet($object->machine_name, $planet)
                || !$this->addsFields($planet, $object->id, $level)) {
                continue;
            }

            $candidates[] = app()->makeWith(BuildCandidate::class, ['buildingId' => $object->id, 'reason' => 'fields:' . $object->machine_name]);
        }

        return $candidates;
    }

    /** Whether one more level of this object raises the planet's field cap; the level is put back before returning. */
    private function addsFields(PlanetService $planet, int $objectId, int $level): bool
    {
        $before = $planet->getPlanetFieldMax();
        $planet->setObjectLevel($objectId, $level + 1, false);
        $after = $planet->getPlanetFieldMax();
        $planet->setObjectLevel($objectId, $level, false);

        return $after > $before;
    }

    /** The fields this planet has left to build on: the host's own cap less the host's own count. */
    private function freeFields(PlanetService $planet): int
    {
        return $planet->getPlanetFieldMax() - $planet->getBuildingCount();
    }

    /** Whether this step would occupy a planet field, which only a building or a station does. */
    private function consumesAField(BuildCandidate $candidate): bool
    {
        $object = ObjectService::getObjectById($candidate->buildingId);

        return ($object->type === GameObjectType::Building || $object->type === GameObjectType::Station)
            && $object->consumesPlanetField;
    }

    /**
     * The build queue and the lab run side by side, so a planet's building and the account's one
     * technology are separate steps: a planet whose best candidate is a technology still builds.
     *
     * @param array<int|string, QueueableBuilding|QueueableResearch> $steps
     * @param callable(PlanetService): list<BuildCandidate> $pass
     * @return array<int|string, QueueableBuilding|QueueableResearch>
     */
    private function withStep(array $steps, PlanetService $planet, AiProfile $profile, callable $pass, ?SavingsGoal $goal, bool $wall = false): array
    {
        $candidates = $pass($planet);
        $isResearch = static fn (BuildCandidate $candidate): bool => ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research;

        // The candidate list is in priority order, so whichever queue its first entry belongs to is tried first.
        $queues = [$planet->getPlanetId() => false, self::LAB_STEP => true];
        if ($candidates !== [] && $isResearch($candidates[0])) {
            $queues = array_reverse($queues, true);
        }

        foreach ($queues as $key => $research) {
            if (isset($steps[$key])) {
                continue;
            }

            $step = $this->firstQueueable($planet, $profile, array_values(array_filter($candidates, static fn (BuildCandidate $candidate): bool => $isResearch($candidate) === $research)), $wall ? null : $goal);
            if ($step !== null) {
                $steps[$key] = $step;
            }
        }

        return $steps;
    }

    /**
     * The first candidate this planet can actually queue, or null when none of them is legal.
     *
     * @param list<BuildCandidate> $candidates
     */
    private function firstQueueable(PlanetService $planet, AiProfile $profile, array $candidates, ?SavingsGoal $goal): QueueableBuilding|QueueableResearch|null
    {
        foreach ($candidates as $candidate) {
            if (!$this->canSpendFor($planet, $goal, $candidate)) {
                continue;
            }

            // Which queue takes a step is the host's object type, not this module's opinion: the
            // chain hands over prerequisites, and a technology among them is research.
            $step = $this->step($planet, $candidate);
            if ($step instanceof QueueableResearch) {
                if ($this->queueableResearch($planet, $candidate) === null) {
                    continue;
                }

                return $step;
            }

            if (!$this->canQueue($planet, $candidate)) {
                continue;
            }

            return $step;
        }

        return null;
    }

    /**
     * Whether the building queue will take this candidate on this planet right now.
     *
     * Public because "the building queue cannot answer this" is a question a second planner has to
     * ask: a planet that cannot buy capacity here has one other route, and the yard may only take it
     * when this says no. The gate itself stays in one place rather than being restated next to it.
     */
    public function canQueue(PlanetService $planet, BuildCandidate $candidate): bool
    {
        return $this->refusal($planet, $candidate) === null;
    }

    /**
     * Whether an order planned earlier can no longer be placed because the queue moved on since: the
     * planet's queue filled, or the station's units went into production. The executor asks this for
     * an order carrying its own binding, so a stale order is planned again from the live state
     * instead of being sent to a host gate that refuses it (STUCK: "Maximum number of items already
     * in queue" and shipyard_busy, repeated by the same accounts).
     */
    public function orderIsStale(int $playerId, int $planetId, int $buildingId): bool
    {
        $player = $this->playerServiceFactory->make($playerId, true);
        $planet = $player->planets->all()[$planetId] ?? null;

        if ($planet === null) {
            foreach ($player->planets->all() as $candidate) {
                if ($candidate->getPlanetId() === $planetId) {
                    $planet = $candidate;
                    break;
                }
            }
        }

        if ($planet === null) {
            return false;
        }

        return $this->buildingQueueService->retrieveQueue($planet)->isQueueFull()
            || $player->isObjectUpgradeBlocked($buildingId);
    }

    /**
     * Whether the station project has its turn, or the mines keep the planet's one build slot.
     *
     * Two answers say the mines have outgrown the stations. The first is the price: when the cheapest
     * upgrade that still repays is a dearer purchase than the cheapest station the catalogue offers it,
     * the station is the small step, and a player whose mines have reached that scale takes it. The
     * comparison is between the planet's own prices, so a fast universe does not age the account into
     * it: measured live 5 Oct 2026 on the 1000x cohort, where a mine that repays in an hour always cost
     * more than the station behind it and the station project waited on a mine level the cohort never
     * reached -- no account in it owned one.
     *
     * The second is the level, because a station behind a graph the planet has not climbed yet is not a
     * purchase a mine level is being weighed against: the nanite factory waits on a level-ten robotics
     * factory, so until that climb happens the cheapest station on offer is one the planet cannot build
     * while its price still holds a deep planet's every mine in front of it (measured live 5 Oct 2026:
     * the robotics factory held at level two and no nanite factory anywhere in the cohort). A planet
     * whose mines have all passed the level that graph names is past the opening, where a mine level is
     * the small purchase, and takes the station however cheap its mines still are.
     *
     * @param list<BuildCandidate> $paying the production upgrades that still repay
     */
    private function minesOutgrownStations(PlanetService $planet, array $paying): bool
    {
        if ($paying === []) {
            return true;
        }

        if ($this->minesPastTheOpening($planet, $paying)) {
            return true;
        }

        $station = $this->cheapestStationPrice($planet);
        if ($station === null) {
            return false;
        }

        foreach ($paying as $candidate) {
            if ($this->candidatePrice($planet, $candidate) < $station) {
                return false;
            }
        }

        return true;
    }

    /** The price of the cheapest station this planet does not hold and can build, or null when it holds every one. */
    private function cheapestStationPrice(PlanetService $planet): ?float
    {
        $cheapest = null;

        foreach (ObjectService::getStationObjects() as $station) {
            if ($planet->getObjectLevel($station->machine_name) > 0
                || !ObjectService::objectValidPlanetType($station->machine_name, $planet)) {
                continue;
            }

            $price = $this->candidatePrice($planet, null, $station->machine_name);
            $cheapest = $cheapest === null ? $price : min($cheapest, $price);
        }

        return $cheapest;
    }

    /**
     * Whether every upgrade the planet is still weighing has passed the level the station project's own
     * requirement graph names.
     *
     * The level is the host's, read from the recursive requirements of the stations this planet does not
     * hold, so a station a mod adds (or deepens) moves it and nothing here names an object. A planet whose
     * mines are all at least that deep is past the opening the level was read from: the mine in front of
     * it is no longer the cheap purchase a young planet makes, and the station the graph leads to is the
     * step that moves the economy -- the nanite factory halves every build after it.
     *
     * @param list<BuildCandidate> $paying the production upgrades that still repay
     */
    private function minesPastTheOpening(PlanetService $planet, array $paying): bool
    {
        $deepest = null;

        foreach (ObjectService::getStationObjects() as $station) {
            if ($planet->getObjectLevel($station->machine_name) > 0
                || !ObjectService::objectValidPlanetType($station->machine_name, $planet)) {
                continue;
            }

            foreach (ObjectService::getRecursiveRequirements($station->machine_name) as $level) {
                $deepest = $deepest === null ? $level : max($deepest, $level);
            }
        }

        if ($deepest === null) {
            return false;
        }

        foreach ($paying as $candidate) {
            $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;
            if ($planet->getObjectLevel($machineName) < $deepest) {
                return false;
            }
        }

        return true;
    }

    /** What the host charges this planet for one step, by its candidate or by its machine name. */
    private function candidatePrice(PlanetService $planet, ?BuildCandidate $candidate, string $machineName = ''): float
    {
        if ($candidate instanceof BuildCandidate) {
            $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;
        }

        return ObjectService::getObjectPrice($machineName, $planet)->sum();
    }

    /**
     * Which of the host's gates refuses this building here, or null when the planet can queue it.
     * The gates are asked in the order the building page asks them: planet type, free queue space,
     * met requirements, a balance it can pay and a field the building still fits in. Named, so a
     * planet that never builds says why instead of reading as idle.
     */
    public function refusal(PlanetService $planet, BuildCandidate $candidate, ?float $reserveHours = ReserveFloor::ECONOMY_HOURS): ?string
    {
        $object = ObjectService::getObjectById($candidate->buildingId);
        $machineName = $object->machine_name;

        if (!ObjectService::objectValidPlanetType($machineName, $planet)) {
            return 'planet type';
        }
        if ($this->buildingQueueService->retrieveQueue($planet)->isQueueFull()) {
            return 'queue full';
        }
        // The host refuses an order for a station whose units are already in production, and the
        // executor asks the same gate (`QueueAiBuildingAction`). Not asked here, the pass offers a
        // step the executor then refuses: the planet spends its one step on a refusal, queues
        // nothing, and still reads buildable to the planner -- idle while called buildable (live:
        // player 44, 4 of 4 planets). Asking it here lets the planet fall through to its next
        // candidate instead.
        if ($planet->getPlayer()?->isObjectUpgradeBlocked($candidate->buildingId) === true) {
            return 'shipyard busy';
        }
        if (!ObjectService::objectRequirementsMetWithQueue($machineName, $planet->getObjectLevel($machineName) + 1, $planet)) {
            return 'requirements';
        }
        if (!$planet->hasResources($this->withReserve($planet, ObjectService::getObjectPrice($machineName, $planet), $reserveHours))) {
            return 'price plus reserve';
        }
        // `BuildingQueueService::start()` refuses a field-consuming building once the planet's fields
        // are used up, and a terraformer cannot rescue it because a terraformer consumes a field too.
        if ($object->consumesPlanetField && $planet->getBuildingCount() >= $planet->getPlanetFieldMax()) {
            return 'no free field';
        }

        return null;
    }

    /**
     * What this planet is saving for: the price plus reserve of the first economy step the planet is
     * short of, or null when it is not saving. A player mines while short, so anything else that spends
     * the same resource waits (the shipyard leaves the balance alone until the step is paid).
     */
    public function savingFor(PlanetService $planet, AiProfile $profile): ?Resources
    {
        $candidate = $this->savingCandidate($planet, $profile);

        return $candidate === null ? null : $this->priceWithReserve($planet, $candidate);
    }

    /**
     * The instant this planet's next step becomes payable, or null when it waits for none: it can
     * already pay, or the resource it is short of is one its own mines do not make.
     *
     * Affordability here is the planner's own word -- the price plus the floor the purchase must
     * leave behind -- so a caller that waits for this answer waits for exactly the purchase the
     * queue would take, and the arithmetic lives in one place instead of once per caller.
     */
    public function savingEta(PlanetService $planet, AiProfile $profile, CarbonImmutable $now): ?CarbonImmutable
    {
        $candidate = $this->savingCandidate($planet, $profile);

        return $candidate === null ? null : $this->payableAt($planet, $this->priceWithReserve($planet, $candidate), $now);
    }

    /**
     * The step this planet is short of, with the instant it becomes payable, or null when it is
     * waiting for none. A login books it: a queue it cannot fill yet is then filled the moment the
     * resources arrive, instead of standing empty until the account's next login (ECON-001 -- the
     * read-out finds such a planet buildable while the login that passed it ordered nothing).
     *
     * @return array{0: QueueableBuilding|QueueableResearch, 1: CarbonImmutable}|null
     */
    public function savingBooking(PlanetService $planet, AiProfile $profile, CarbonImmutable $now): ?array
    {
        $candidate = $this->savingCandidate($planet, $profile);
        if ($candidate === null) {
            return null;
        }

        $dueAt = $this->payableAt($planet, $this->priceWithReserve($planet, $candidate), $now);

        return $dueAt === null ? null : [$this->step($planet, $candidate), $dueAt];
    }

    /** The price of one candidate plus the floor that must survive buying it. */
    private function priceWithReserve(PlanetService $planet, BuildCandidate $candidate): Resources
    {
        $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;

        return $this->withReserve($planet, ObjectService::getObjectPrice($machineName, $planet), ReserveFloor::ECONOMY_HOURS);
    }

    /** When this planet holds what the purchase needs, or null when its own income never brings it there. */
    private function payableAt(PlanetService $planet, Resources $needed, CarbonImmutable $now): ?CarbonImmutable
    {
        $held = $planet->getResources();
        $hours = 0.0;

        foreach ([
            [$needed->metal->get(), $held->metal->get(), $planet->getMetalProductionPerHour()],
            [$needed->crystal->get(), $held->crystal->get(), $planet->getCrystalProductionPerHour()],
            [$needed->deuterium->get(), $held->deuterium->get(), $planet->getDeuteriumProductionPerHour()],
        ] as [$cost, $stored, $perHour]) {
            if ($cost <= $stored) {
                continue;
            }

            if ($perHour <= 0.0) {
                return null;
            }

            $hours = max($hours, ($cost - $stored) / $perHour);
        }

        return $hours <= 0.0 ? null : $now->addSeconds((int) ceil($hours * 3600.0));
    }

    /** The order a candidate turns into, on the queue the host's own object type says it belongs to. */
    private function step(PlanetService $planet, BuildCandidate $candidate): QueueableBuilding|QueueableResearch
    {
        $fields = ['planetId' => $planet->getPlanetId(), 'reason' => $candidate->reason];

        return ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research
            ? app()->makeWith(QueueableResearch::class, [...$fields, 'researchId' => $candidate->buildingId])
            : app()->makeWith(QueueableBuilding::class, [...$fields, 'buildingId' => $candidate->buildingId]);
    }

    /** The first step in the planner's own order this planet cannot yet pay for, or null when it can pay for all of them. */
    private function savingCandidate(PlanetService $planet, AiProfile $profile): ?BuildCandidate
    {
        foreach ($this->passes($profile) as $pass) {
            foreach ($pass($planet) as $candidate) {
                if ($this->refusal($planet, $candidate) === 'price plus reserve') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * What this planet is holding resources back for, or null when it spends freely.
     *
     * Only a goal saver holds anything back (PERS-007). The strategy decides how the pile is spent, never
     * whether the account spends at all: every other strategy leaves the balance alone, so the decision
     * doctrine's spend-first rule stands for the immediate spender. The goal is the first step the planet
     * cannot yet pay for -- the step it would buy the moment it could -- and no object is named here, so
     * a goal it reaches is replaced by the next one with no edit.
     */
    private function savingsGoal(PlanetService $planet, AiProfile $profile): ?SavingsGoal
    {
        // The profile's own cast is the gate: a strategy the module does not manage leaves the
        // balance alone, so nothing is held back and the account spends as before.
        if ($profile->stockpile_strategy !== AiStockpileStrategy::GoalSaver) {
            return null;
        }

        // A score that has not moved for the window is a goal that is not arriving: the account stops reserving the
        // pile for it and spends what it holds (IMPL-69), the same release the reserve floor makes.
        if (app(StalledGrowthDetector::class)->stalled($profile->player_id)) {
            return null;
        }

        $candidate = $this->savingCandidate($planet, $profile);
        if ($candidate === null) {
            return null;
        }

        $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;

        return app()->makeWith(SavingsGoal::class, [
            'cost' => ObjectService::getObjectPrice($machineName, $planet),
            'objectId' => $candidate->buildingId,
        ]);
    }

    /**
     * Whether the planet can pay for this candidate out of what the goal leaves spendable.
     *
     * A saving player holds the goal's price back and spends what is left over, so a cheaper upgrade
     * that would burn the reserved pile waits its turn. The goal's own purchase is the exception:
     * spending the reserve is what the reserve is for.
     *
     * The comparison is against the price alone, because the reserve already is the margin that must
     * survive the purchase: the goal was named precisely because the planet cannot yet pay its price
     * plus the same floor, so spendable = available - reserved is always below that floor and a price
     * plus floor again can never fit inside it. Measured that way the branch never passes and a goal
     * saver stops playing entirely -- frozen on every planet until the goal is reached, which is the
     * strategy deciding *whether* the account spends rather than how (PERS-007). Every other spend
     * still has to clear refusal()'s price-plus-reserve gate, so the floor is not lost, only not
     * charged twice against the reserve that exists to carry it.
     */
    private function canSpendFor(PlanetService $planet, ?SavingsGoal $goal, BuildCandidate $candidate): bool
    {
        if ($goal === null || $goal->covers($candidate->buildingId)) {
            return true;
        }

        $price = ObjectService::getObjectPrice(ObjectService::getObjectById($candidate->buildingId)->machine_name, $planet);
        $spendable = $goal->spendable($planet->getResources());

        return $spendable->metal->get() >= $price->metal->get()
            && $spendable->crystal->get() >= $price->crystal->get()
            && $spendable->deuterium->get() >= $price->deuterium->get();
    }

    /**
     * The same question for a technology, asked the way the host's research page asks it.
     *
     * Research is account-wide on the host's screen while its queue and its requirements are per
     * planet, so the planet that answers is the planet whose laboratory carries it. Nothing here
     * names a technology: the price, the requirement graph and the queue are all the host's.
     */
    private function queueableResearch(PlanetService $planet, BuildCandidate $candidate, ?float $reserveHours = ReserveFloor::RESEARCH_HOURS): ?QueueableResearch
    {
        $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;

        // A technology already in research anywhere on the account is not offered again: the host
        // would take the row and then cancel it, so the capability is withheld instead of the
        // decision being spent on a refusal.
        if ($this->researchQueueService->activeResearchQueueItemCount($planet->getPlayer(), $candidate->buildingId) > 0) {
            return null;
        }

        $queueable = !$this->researchQueueService->retrieveQueue($planet)->isQueueFull()
            && ObjectService::objectRequirementsMetWithQueue($machineName, ($planet->getPlayer()?->getResearchLevel($machineName) ?? 0) + 1, $planet)
            && $planet->hasResources($this->withReserve($planet, ObjectService::getObjectPrice($machineName, $planet), $reserveHours));

        if (!$queueable) {
            return null;
        }

        return app()->makeWith(QueueableResearch::class, [
            'planetId' => $planet->getPlanetId(),
            'researchId' => $candidate->buildingId,
            'reason' => $candidate->reason,
        ]);
    }

    /**
     * The price plus the floor the purchase must leave behind, so affordability stays one host call.
     *
     * A resource the price does not spend keeps no floor: the floor is what must survive spending that
     * resource, so a purchase that costs no deuterium is not blocked by a deuterium reserve (SP5 --
     * saving for a drive must not freeze surplus metal and crystal).
     */
    private function withReserve(PlanetService $planet, Resources $price, ?float $savingHours): Resources
    {
        // No saving horizon is the host's own question: can the planet pay the price at all.
        if ($savingHours === null) {
            return $price;
        }

        $floor = $this->reserveFloor->floor($planet, $savingHours);

        return new Resources(
            $price->metal->get() > 0 ? $price->metal->get() + $floor->metal->get() : 0,
            $price->crystal->get() > 0 ? $price->crystal->get() + $floor->crystal->get() : 0,
            $price->deuterium->get() > 0 ? $price->deuterium->get() + $floor->deuterium->get() : 0,
            $price->energy->get(),
        );
    }
}
