<?php

namespace Modules\AI\Domain\Perception;

use Carbon\CarbonImmutable;
use Modules\AI\Actions\CurrentAiAffectIntensityAction;
use Modules\AI\Domain\Decision\QueueableBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableColonyPlanner;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Domain\Decision\SaveFailurePolicy;
use Modules\AI\Domain\Lifecycle\AccountStateResolver;
use Modules\AI\Enums\AiAccountState;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\DeploymentMission;
use OGame\GameMissions\EspionageMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Message;
use OGame\Models\Planet\Coordinate;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Reduces only the AI account's own current state into a transport-safe input.
 *
 * Enemy intelligence enters through explicitly published reports elsewhere;
 * keeping it out here prevents a future policy from accidentally gaining host
 * model access it was never meant to have.
 */
class PlayerObservationService
{
    /**
     * How long one espionage report stays fresh enough to raid from, before the
     * target has to be re-scouted. A stale target dies on its own; the account
     * does not raid blind.
     */
    private const INTEL_TTL_HOURS = 24;

    /** RAID-008: a target scoring under this fraction of ours is not worth the fleet. */
    private const VIABILITY_SCORE_DIVISOR = 5;

    /** CL3: the storage horizon (48 h) a colony's opening must repay inside, from E3. */
    private const COLONY_DEVELOPMENT_HOURS = 48.0;

    /** V2: the reaction window a hostile inbound wakes the account inside, in seconds before impact. */
    private const REACTION_WINDOW_MIN_SECONDS = 120;

    private const REACTION_WINDOW_MAX_SECONDS = 180;

    /** V2: the host's own bot detector floor; a save closer than this to impact reads as scripted. */
    private const REACTION_FLOOR_SECONDS = 10;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private ActivityIntelReader $activityIntelReader,
        private SettingsService $settings,
        private QueueableBuildingPlanner $queueableBuildingPlanner,
        private QueueableUnitPlanner $queueableUnitPlanner,
        private QueueableColonyPlanner $queueableColonyPlanner,
        private QueueableFleetSavePlanner $queueableFleetSavePlanner,
        private QueueableSpyPlanner $queueableSpyPlanner,
        private SaveFailurePolicy $saveFailurePolicy,
        private AccountStateResolver $accountStateResolver,
        private RandomSource $randomSource,
    ) {
    }

    /**
     * @return array{
     *     player_id:int,
     *     observed_at:int,
     *     account_state:string,
     *     recovery_factor:float,
     *     planets:array<int, array{id:int, resources:array<string, float|int>}>,
     *     available_actions:array<string, bool>,
     *     target_reports:array<int, array<string, mixed>>,
     *     fleetsave_eligible:bool,
     *     fleetsave_skip_reason:string|null,
     *     inbound_fleets:list<array{mission_id:int, mission_type:int, time_arrival:int, planet_id_to:int}>,
     *     reaction_wake_at:int|null,
     *     recall_eligible:bool
     * }
     */
    public function ownedState(int $playerId): array
    {
        $state = $this->accountStateResolver->resolve($playerId);
        $active = $state === AiAccountState::Active;

        return [
            'player_id' => $playerId,
            'observed_at' => (int) now()->timestamp,
            'account_state' => $state->value,
            // W9-2: the scorer has always read this signal; publish it so a live
            // decision carries the account's real, decaying recovery pressure.
            'recovery_factor' => $active ? $this->recoveryFactor($playerId) : 0.0,
            'planets' => $state === AiAccountState::Final ? [] : $this->planets($playerId),
            // A suspended account is not playing, and the host is the authority
            // on that state: a banned or vacationing account is offered nothing
            // rather than a capability it cannot act on, so its session records
            // that it did nothing instead of recording a decision the host
            // would refuse. An account with no planets, and one the host no
            // longer has, are offered nothing for the same reason.
            'available_actions' => $active ? $this->availableActions($playerId) : [],
            // The host's fleet-slot ceiling decides which dispatches may be published: a
            // colony, spy, raid, expedition or transfer the host would refuse for slot
            // exhaustion is never offered (SP8). Reaching the object that raises the
            // ceiling is a host obligation (R11), not a module-side object list.
            'fleet_slots_free' => $active ? $this->freeFleetSlots($playerId) : 0,
            // CL3: a colony is founded only when the account's own production can bring it
            // online — a body the account cannot develop outranks nothing and sits at zero.
            'colonize_eligible' => $active && $this->canDevelopColony($playerId),
            // Enemy intel arrives through the host's own espionage-report
            // messages, never from this module reaching into target state.
            'target_reports' => $active ? $this->targetReports($playerId) : [],
            // Inbound fleets are assembled from the host's active fleet missions the same way
            // the fleet movement page does. IncomingFleetIntelService only redacts a row that
            // already exists; it is not the source of the inbound picture.
            ...$this->inboundThreat($playerId, $active),
            ...$this->recallState($playerId, $active),
        ];
    }

    /**
     * The host's own fleet-slot answer, reduced to the free count a dispatch would need.
     */
    private function freeFleetSlots(int $playerId): int
    {
        $player = $this->playerServiceFactory->make($playerId, true);

        return max(0, $player->getFleetSlotsMax() - $player->getFleetSlotsInUse());
    }

    /**
     * Whether the account's own production can fund a new colony's opening (CL3).
     *
     * A colony starts at zero, and developing it is paid from what the account already produces;
     * the cheapest production object the host offers is the opening step, and the account is eligible
     * once its production covers that cost inside the storage horizon (the same 48 h E3 uses). The
     * host supplies every object and price, so a mod that changes either changes this gate with no
     * module edit.
     */
    private function canDevelopColony(int $playerId): bool
    {
        $player = $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();

        $perHour = 0.0;

        foreach ($planets as $planet) {
            $perHour += $planet->getMetalProductionPerHour()
                + $planet->getCrystalProductionPerHour()
                + $planet->getDeuteriumProductionPerHour();
        }

        $cheapest = PHP_FLOAT_MAX;

        foreach (ObjectService::getGameObjectsWithProduction() as $object) {
            $price = ObjectService::getObjectRawPrice($object->machine_name);
            $cheapest = min($cheapest, $price->metal->get() + $price->crystal->get() + $price->deuterium->get());
        }

        return $perHour * self::COLONY_DEVELOPMENT_HOURS >= $cheapest;
    }

    /**
     * W9-2: the recovery signal the scorer has always read but never received.
     *
     * The only persisted, decaying setback signal is affect intensity: a battle the account
     * came off worse in appraises to Anger (NativeAffectEngine) and decays 0.25/day, so the
     * still-raw anger is the "not yet recovered" reading. ponytail: anger is a proxy — a true
     * losses-vs-rebuilt ratio belongs to the experience layer (WP-015), and the affect decay is
     * the closest existing bounded signal.
     */
    private function recoveryFactor(int $playerId): float
    {
        return app(CurrentAiAffectIntensityAction::class)->handle(
            $playerId,
            AiAffectEmotion::Anger,
            CarbonImmutable::instance(now()),
        );
    }

    /**
     * The espionage reports this account has received and can still act on.
     *
     * The host delivers a report as a message row carrying `espionage_report_id`;
     * that row is the account's own record, so reading it here publishes only
     * what the account has been told. The actual target state stays with the
     * report and the estimator — no target model reaches a policy from here.
     *
     * @return array<int, array{report_id:int, observed_at:int, expires_at:int, confidence:float, travel_cost:float, activity:bool|null, attack_permitted:bool, score_viable:bool}>
     */
    private function targetReports(int $playerId): array
    {
        $now = now();
        $cutoff = $now->copy()->subHours(self::INTEL_TTL_HOURS);

        $messages = Message::query()
            ->where('user_id', $playerId)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['espionage_report_id', 'created_at']);

        if ($messages->isEmpty()) {
            return [];
        }

        $reports = EspionageReport::query()
            ->whereIn('id', $messages->pluck('espionage_report_id')->all())
            ->get()
            ->keyBy('id');

        $nowTimestamp = (int) $now->timestamp;
        $player = $this->playerServiceFactory->make($playerId, true);

        // The public highscore is the one score both sides can see: a target
        // under ~⅕ of ours cannot defend its loot economically, so it is
        // dropped before the profit test runs (RAID-008).
        $ownScore = (int) (Highscore::query()->where('player_id', $playerId)->value('general') ?? 0);
        $targetUserIds = $reports->pluck('planet_user_id')->unique()->filter()->all();
        $targetScores = $targetUserIds === []
            ? collect()
            : Highscore::query()->whereIn('player_id', $targetUserIds)->get()->keyBy('player_id');

        return $messages
            ->map(function (Message $message) use ($reports, $nowTimestamp, $player, $ownScore, $targetScores): array {
                // The messages column is a foreign key to espionage_reports with
                // no cascade, so every message here has a report by construction.
                /** @var EspionageReport $report */
                $report = $reports->get((int) $message->espionage_report_id);
                $targetScore = $targetScores->get((int) $report->planet_user_id);

                return [
                    'report_id' => (int) $message->espionage_report_id,
                    'observed_at' => (int) ($message->created_at->timestamp ?? 0),
                    'expires_at' => (int) ($message->created_at?->addHours(self::INTEL_TTL_HOURS)->timestamp ?? 0),
                    // The profit test reads loot, fleet and defence, all of which go
                    // stale fast; confidence is that fast-type freshness (RAID-005).
                    'confidence' => $this->activityIntelReader->intelConfidence(
                        (int) ($message->created_at->timestamp ?? 0),
                        $nowTimestamp,
                        self::INTEL_TTL_HOURS,
                        'resources',
                    ),
                    // A normalized host distance: the planner enforces the exact
                    // fuel cost in its gate, and this lets the scorer prefer the
                    // closer target among what remains (RAID-006).
                    'travel_cost' => $this->travelCost($player, $report),
                    // The 15-minute activity star is galaxy-visible, so publishing
                    // it here is a legal observation, not a reach into target state.
                    'activity' => $this->targetActivity($report),
                    // Legality mirrors the host's own AttackMission checks (own body,
                    // vacation, banned, admin) instead of trusting the intel is attackable.
                    'attack_permitted' => $this->attackPermitted($player, $report),
                    'score_viable' => $this->scoreViable($ownScore, $targetScore === null ? 0 : (int) $targetScore->general),
                ];
            })
            ->all();
    }

    /**
     * A target scoring under ~⅕ of ours cannot defend economically, so the
     * account does not farm newbies (RAID-008). An unknown own score filters
     * nothing: a young universe has no score row yet, and skipping everything
     * is worse than skipping nothing.
     */
    private function scoreViable(int $ownScore, int $targetScore): bool
    {
        if ($ownScore === 0) {
            return true;
        }

        return $targetScore >= intdiv($ownScore, self::VIABILITY_SCORE_DIVISOR);
    }

    /**
     * The distance from the account's nearest planet to the report's target,
     * normalized by the host's own galaxy span, as a 0..1 cost (RAID-006).
     */
    private function travelCost(PlayerService $player, EspionageReport $report): float
    {
        $target = new Coordinate((int) $report->planet_galaxy, (int) $report->planet_system, (int) $report->planet_position);
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);

        $minDistance = null;
        foreach ($player->planets->all() as $planet) {
            $distance = $fleetMissions->calculateFleetMissionDistance($planet, $target);
            $minDistance = $minDistance === null ? $distance : min($minDistance, $distance);
        }

        $maxDistance = (int) $this->settings->numberOfGalaxies() * 20_000;

        return $maxDistance > 0 ? min(1.0, (float) ($minDistance ?? 0) / $maxDistance) : 0.0;
    }

    /** @return bool|null the target body's activity star, or null when the body no longer exists */
    private function targetActivity(EspionageReport $report): ?bool
    {
        $target = $this->planetServiceFactory->makeForCoordinate(
            new Coordinate((int) $report->planet_galaxy, (int) $report->planet_system, (int) $report->planet_position),
            false,
            PlanetType::from((int) $report->planet_type),
        );

        return $target === null ? null : $this->activityIntelReader->activityAt($target);
    }

    /**
     * Whether the host would allow an attack on the reported body: the same questions
     * AttackMission asks — the body still exists and has an owner, it is not the
     * account's own, and its owner is not vacationing, banned or admin-protected.
     * Bashing and profit stay the raid planner's job.
     */
    private function attackPermitted(PlayerService $player, EspionageReport $report): bool
    {
        $target = $this->planetServiceFactory->makeForCoordinate(
            new Coordinate((int) $report->planet_galaxy, (int) $report->planet_system, (int) $report->planet_position),
            false,
            PlanetType::from((int) $report->planet_type),
        );

        $targetPlayer = $target?->getPlayer();

        if ($targetPlayer === null || $player->equals($targetPlayer)) {
            return false;
        }

        return !$targetPlayer->isInVacationMode()
            && !$targetPlayer->isBanned()
            && !$targetPlayer->isAdmin();
    }

    /** @return array<int, array{id:int, resources:array<string, float|int>}> */
    private function planets(int $playerId): array
    {
        $planets = [];
        foreach ($this->playerServiceFactory->make($playerId, true)->planets->all() as $planet) {
            $planets[] = [
                'id' => $planet->getPlanetId(),
                'resources' => [
                    'metal' => $planet->metal()->get(),
                    'crystal' => $planet->crystal()->get(),
                    'deuterium' => $planet->deuterium()->get(),
                ],
            ];
        }

        return $planets;
    }

    /**
     * Only a capability the module can actually carry out is published.
     *
     * Publishing one it cannot is how the population came to decide without ever acting: a trace
     * would claim an action while the host was never touched, which reads as a quiet population
     * rather than as the gap it is. The economy plan answers with the queue the next step belongs
     * to, and the unit plan answers separately once a shipyard exists -- only those answers are
     * published.
     *
     * @return array<string, bool>
     */
    private function availableActions(int $playerId): array
    {
        $step = $this->queueableBuildingPlanner->plan($playerId);

        return [
            AiCapability::Build->value => $step instanceof QueueableBuilding,
            AiCapability::Research->value => $step instanceof QueueableResearch,
            AiCapability::QueueUnits->value => $this->queueableUnitPlanner->plan($playerId) !== null,
            AiCapability::Colonize->value => $this->queueableColonyPlanner->plan($playerId) !== null,
            AiCapability::Spy->value => $this->queueableSpyPlanner->plan($playerId) !== null,
        ];
    }

    /**
     * Foreign fleets headed at this account, and whether the host says that means under attack.
     *
     * Assembled from the host's active fleet missions the same way the fleet movement page does.
     * Which missions count as hostile is the host's `currentPlayerUnderAttack()` answer — this
     * module does not keep a mission-type list. The inbound rows carry only what every account
     * can see without espionage (id, type, ETA, destination); composition stays with the host's
     * redactor on the movement page.
     *
     * @return array{
     *     fleetsave_eligible:bool,
     *     fleetsave_skip_reason:string|null,
     *     inbound_fleets:list<array{mission_id:int, mission_type:int, time_arrival:int, planet_id_to:int}>,
     *     reaction_wake_at:int|null
     * }
     */
    private function inboundThreat(int $playerId, bool $active): array
    {
        if (!$active) {
            return [
                'fleetsave_eligible' => false,
                'fleetsave_skip_reason' => null,
                'inbound_fleets' => [],
                'reaction_wake_at' => null,
            ];
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);

        $inbound = [];
        $threatening = false;
        foreach ($fleetMissions->getActiveFleetMissionsForCurrentPlayer() as $mission) {
            if ($mission->user_id === $playerId) {
                continue;
            }

            $inbound[] = [
                'mission_id' => (int) $mission->id,
                'mission_type' => (int) $mission->mission_type,
                'time_arrival' => (int) $mission->time_arrival,
                'planet_id_to' => (int) $mission->planet_id_to,
            ];

            // A spy probe alone is not a reason to move: only a non-espionage
            // inbound is a threat worth saving from (FS-012). The type is the
            // host's own mission-type answer, never a module list.
            $threatening = $threatening || $mission->mission_type !== EspionageMission::getTypeId();
        }

        // A fleetsave candidate is only offered when it is both needed and possible: the host
        // says a hostile is inbound, and the account has a fleet and a second body to move it
        // to. Offering it otherwise is how a trace claims an action the account cannot take.
        $saveable = $fleetMissions->currentPlayerUnderAttack()
            && $threatening
            && $this->queueableFleetSavePlanner->plan($playerId) !== null;
        $seed = AiProfile::query()->where('player_id', $playerId)->value('random_seed');
        $seed = $seed === null ? null : (int) $seed;

        // V2: a reaction lands inside the 120–180 s window before impact, never instantly and
        // never below the host's 10 s detector floor. An early notice withholds the save and
        // publishes the reaction wake instead; a notice past the floor is a doomed save the
        // account does not attempt.
        $earliestArrival = $inbound === [] ? null : (int) min(array_column($inbound, 'time_arrival'));
        $reactionWakeAt = null;

        if ($saveable && $earliestArrival !== null) {
            $secondsToImpact = $earliestArrival - (int) now()->timestamp;

            if ($secondsToImpact > self::REACTION_WINDOW_MAX_SECONDS) {
                $reactionWakeAt = $earliestArrival - $this->reactionLeadSeconds($seed);
                $saveable = false;
            } elseif ($secondsToImpact < self::REACTION_FLOOR_SECONDS) {
                $saveable = false;
            }
        }

        // A save that fails is decided against a specific inbound fleet, so the same threat gets
        // the same judgement however many times the session re-reads it.
        if ($saveable) {
            $key = $inbound === [] ? 0 : (int) min(array_column($inbound, 'mission_id'));
            $skipReason = $this->saveFailurePolicy->shouldSkip($seed, $key);

            if ($skipReason !== null) {
                return [
                    'fleetsave_eligible' => false,
                    'fleetsave_skip_reason' => $skipReason,
                    'inbound_fleets' => $inbound,
                    'reaction_wake_at' => null,
                ];
            }
        }

        return [
            'fleetsave_eligible' => $saveable,
            'fleetsave_skip_reason' => null,
            'inbound_fleets' => $inbound,
            'reaction_wake_at' => $reactionWakeAt,
        ];
    }

    /**
     * The seconds before impact the account wakes to react: a deterministic per-account draw
     * inside the 120–180 s window, so the same inbound always gets the same reaction and the
     * reaction never lands below the host's 10 s detector floor.
     */
    private function reactionLeadSeconds(?int $seed): int
    {
        $unit = $this->randomSource->unitInterval($seed ?? 0, 'reaction-wake');

        return self::REACTION_WINDOW_MIN_SECONDS
            + (int) round((self::REACTION_WINDOW_MAX_SECONDS - self::REACTION_WINDOW_MIN_SECONDS) * $unit);
    }

    /**
     * The recall is the other half of a save: the parked deployment comes home
     * once the hostile that sent it away is gone. A deployment between two own
     * bodies only, and never while the host still reports an attack.
     *
     * @return array{recall_eligible:bool}
     */
    private function recallState(int $playerId, bool $active): array
    {
        if (!$active) {
            return ['recall_eligible' => false];
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $deploymentInFlight = FleetMission::query()
            ->where('user_id', $playerId)
            ->where('mission_type', DeploymentMission::getTypeId())
            ->where('canceled', 0)
            ->where('processed', 0)
            ->where('time_arrival', '>=', now()->timestamp)
            ->whereColumn('planet_id_from', '!=', 'planet_id_to')
            ->exists();

        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);

        return ['recall_eligible' => $deploymentInFlight && !$fleetMissions->currentPlayerUnderAttack()];
    }
}
