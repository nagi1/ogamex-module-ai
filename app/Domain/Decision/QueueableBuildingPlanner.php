<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Persona\SavingsGoal;
use Modules\AI\Enums\AiStockpileStrategy;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\ResearchQueueService;

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
            'storage' => fn (PlanetService $planet): array => $this->economyUpgrades->storage($planet, $profile),
            'surplus' => fn (PlanetService $planet): array => $this->economyUpgrades->spendSurplus($planet, $profile),
            // A planet sitting on six times the price of a facility it does not own buys it before another
            // mine: the nano factory, terraformer and the rest are what a human with a pile builds (COVER-*).
            'ambition' => fn (PlanetService $planet): array => $this->economyUpgrades->ambitions($planet),
            'routine' => fn (PlanetService $planet): array => [
                ...$this->energyCapacity->pending($planet),
                ...$this->facilityChain->pending($planet),
                ...$this->economyUpgrades->storageForPrice($planet, $profile),
                ...$this->economyUpgrades->production($planet, $profile),
            ],
        ];
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
            if (ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research) {
                $research = $this->queueableResearch($planet, $candidate);
                if ($research === null) {
                    continue;
                }

                return $research;
            }

            $planetId = $planet->getPlanetId();
            if (!$this->canQueue($planet, $candidate)) {
                continue;
            }

            return app()->makeWith(QueueableBuilding::class, [
                'planetId' => $planetId,
                'buildingId' => $candidate->buildingId,
                'reason' => $candidate->reason,
            ]);
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
     * Which of the host's gates refuses this building here, or null when the planet can queue it.
     * The gates are asked in the order the building page asks them: planet type, free queue space,
     * met requirements, a balance it can pay and a field the building still fits in. Named, so a
     * planet that never builds says why instead of reading as idle.
     */
    public function refusal(PlanetService $planet, BuildCandidate $candidate): ?string
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
        if (!$planet->hasResources($this->withReserve($planet, ObjectService::getObjectPrice($machineName, $planet), ReserveFloor::ECONOMY_HOURS))) {
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
        if ($candidate === null) {
            return null;
        }

        return $this->withReserve($planet, ObjectService::getObjectPrice(ObjectService::getObjectById($candidate->buildingId)->machine_name, $planet), ReserveFloor::ECONOMY_HOURS);
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
    private function queueableResearch(PlanetService $planet, BuildCandidate $candidate): ?QueueableResearch
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
            && $planet->hasResources($this->withReserve($planet, ObjectService::getObjectPrice($machineName, $planet), ReserveFloor::RESEARCH_HOURS));

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
    private function withReserve(PlanetService $planet, Resources $price, float $savingHours): Resources
    {
        $floor = $this->reserveFloor->floor($planet, $savingHours);

        return new Resources(
            $price->metal->get() > 0 ? $price->metal->get() + $floor->metal->get() : 0,
            $price->crystal->get() > 0 ? $price->crystal->get() + $floor->crystal->get() : 0,
            $price->deuterium->get() > 0 ? $price->deuterium->get() + $floor->deuterium->get() : 0,
            $price->energy->get(),
        );
    }
}
