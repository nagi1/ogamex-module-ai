<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableColony;
use Modules\AI\Domain\Decision\QueueableColonyPlanner;
use Modules\AI\Domain\Decision\QueueableExpedition;
use Modules\AI\Domain\Decision\QueueableExpeditionPlanner;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Decision\QueueableMinePercent;
use Modules\AI\Domain\Decision\QueueableMinePercentPlanner;
use Modules\AI\Domain\Decision\QueueablePhalanx;
use Modules\AI\Domain\Decision\QueueablePhalanxPlanner;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\QueueableRecall;
use Modules\AI\Domain\Decision\QueueableRecycle;
use Modules\AI\Domain\Decision\QueueableRecyclePlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\QueueableDefend;
use Modules\AI\Domain\Decision\QueueableDefendPlanner;
use Modules\AI\Domain\Decision\QueueableJumpGate;
use Modules\AI\Domain\Decision\QueueableJumpGatePlanner;
use Modules\AI\Domain\Decision\QueueableRelocation;
use Modules\AI\Domain\Decision\QueueableRelocationPlanner;
use Modules\AI\Domain\Decision\QueueableTrade;
use Modules\AI\Domain\Decision\QueueableTradePlanner;
use Modules\AI\Domain\Decision\QueueableTransfer;
use Modules\AI\Domain\Decision\QueueableTransferPlanner;
use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Domain\Decision\SaveFailurePolicy;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Decision\ThreatResponsePlan;
use Modules\AI\Domain\Decision\ThreatResponsePlanner;
use Modules\AI\Domain\Lifecycle\AccountStateResolver;
use Modules\AI\Domain\Login\FleetSlots;
use Modules\AI\Domain\Login\GamePhaseMachine;
use Modules\AI\Domain\Login\GoalBoard;
use Modules\AI\Domain\Login\LoginReservations;
use Modules\AI\Domain\Login\ManagerDoctrine;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Enums\AiThreatResponse;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiGoal;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Planet;
use OGame\Models\UnitQueue;

/**
 * Turns a session's selected intent into the one work item that carries it out.
 *
 * Recording the decision and acting on it stay separate steps: the decision is traceable whether or
 * not the host would allow it, and only an intent with a real executor becomes work. An intent
 * without one stays in the trace rather than becoming a work item that quietly does nothing.
 */
class ScheduleAiIntentAction
{
    private const PAYLOAD_PLANET_ID = 'planet_id';

    private const PAYLOAD_BUILDING_ID = 'building_id';

    private const PAYLOAD_RESEARCH_ID = 'research_id';

    private const PAYLOAD_UNIT_ID = 'unit_id';

    private const PAYLOAD_AMOUNT = 'amount';

    private const PAYLOAD_GALAXY = 'galaxy';

    private const PAYLOAD_SYSTEM = 'system';

    private const PAYLOAD_POSITION = 'position';

    private const PAYLOAD_MISSION_TYPE = 'mission_type';

    private const PAYLOAD_LAUNCH_UNITS = 'launch_units';

    private const PAYLOAD_DESTINATION_PLANET_ID = 'destination_planet_id';

    private const PAYLOAD_SHADOW_DESTINATION_PLANET_ID = 'shadow_destination_planet_id';

    private const PAYLOAD_TARGET_GALAXY = 'target_galaxy';

    private const PAYLOAD_TARGET_SYSTEM = 'target_system';

    private const PAYLOAD_TARGET_POSITION = 'target_position';

    private const PAYLOAD_TARGET_TYPE = 'target_type';

    private const PAYLOAD_PROBE_COUNT = 'probe_count';

    private const PAYLOAD_SOURCE_PLANET_ID = 'source_planet_id';

    private const PAYLOAD_TARGET_PLANET_ID = 'target_planet_id';

    private const PAYLOAD_METAL = 'metal';

    private const PAYLOAD_CRYSTAL = 'crystal';

    private const PAYLOAD_DEUTERIUM = 'deuterium';

    private const PAYLOAD_REASON = 'reason';

    private const PAYLOAD_SPEED = 'speed';

    private const PAYLOAD_JUMP_GATE_PLANET_ID = 'jump_gate_planet_id';

    private const PAYLOAD_PERCENTAGE = 'percentage';

    /**
     * The gap between one planet's order and the next in a login, the way a player clicks through
     * planets. It also keeps a session's intents from reaching the workers in the same instant, where
     * all but one lose the per-player lock and wait out a retry.
     */
    private const SECONDS_BETWEEN_PLANETS = 20;

    /** The key suffix of the manager placing the next order; empty for the session's own objective. */
    private string $managerSuffix = '';

    /** Whether the last schedule call wrote an order, so a manager knows when its planner ran dry. */
    private bool $enqueued = false;

    public function __construct(
        private QueueableBuildingPlanner $queueableBuildingPlanner,
        private QueueableUnitPlanner $queueableUnitPlanner,
        private QueueableColonyPlanner $queueableColonyPlanner,
        private QueueableExpeditionPlanner $queueableExpeditionPlanner,
        private QueueableFleetSavePlanner $queueableFleetSavePlanner,
        private QueueableSpyPlanner $queueableSpyPlanner,
        private QueueableTransferPlanner $queueableTransferPlanner,
        private QueueableRecyclePlanner $queueableRecyclePlanner,
        private QueueableMinePercentPlanner $queueableMinePercentPlanner,
        private QueueablePhalanxPlanner $queueablePhalanxPlanner,
        private QueueableDefendPlanner $queueableDefendPlanner,
        private QueueableTradePlanner $queueableTradePlanner,
        private QueueableRelocationPlanner $queueableRelocationPlanner,
        private QueueableJumpGatePlanner $queueableJumpGatePlanner,
        private RaidPlanner $raidPlanner,
        private SaveFailurePolicy $saveFailurePolicy,
        private ThreatResponsePlanner $threatResponsePlanner,
        private AccountStateResolver $accountStateResolver,
        private AiClock $clock,
        private GoalBoard $goalBoard,
    ) {
    }

    /**
     * Writes the one work item that carries the intent out, keyed by the session's
     * own id: a retried session converges on one action, a later session decides
     * again. The payload is whatever the plan approved, so the schedule and the
     * executor always name the same objective.
     *
     * @param array<string, mixed> $payload
     */
    private function enqueue(AiProfile $profile, AiWorkItem $sessionWorkItem, AiWorkKind $kind, array $payload, ?CarbonImmutable $dueAt = null, string $keySuffix = ''): void
    {
        // A manager's extra order carries the manager's own key, so the session's objective keeps the
        // primary key and a retried login converges on the same set of orders (architecture step 3).
        $keySuffix = $keySuffix !== '' ? $keySuffix : $this->managerSuffix;
        $this->enqueued = true;

        AiWorkItem::query()->firstOrCreate(
            ['idempotency_key' => 'intent:session:' . $sessionWorkItem->id . $keySuffix],
            [
                'player_id' => $profile->player_id,
                'kind' => $kind,
                'due_at' => $dueAt ?? $this->clock->now(),
                'schedule_generation' => (int) ($sessionWorkItem->schedule_generation ?? 1),
                'state' => AiWorkState::Pending,
                'payload' => $payload,
            ],
        );
    }

    public function handle(AiProfile $profile, AiWorkItem $sessionWorkItem, DecisionTrace $trace): void
    {
        // The login's claims start empty and are cleared again when it ends, so a worker process that runs
        // the next account's login never carries this one's promised ships.
        app(LoginReservations::class)->reset();

        try {
            $this->schedule($profile, $sessionWorkItem, $trace);
        } finally {
            app(LoginReservations::class)->reset();
            $this->managerSuffix = '';
        }
    }

    /**
     * The account's own objective, written so that it outlives this login. A person works towards the
     * same rung for days, while a login that re-decides from scratch has intentions no longer than one
     * session, so the goal the account already holds is re-affirmed with what it owns today and only an
     * account whose goal was met or given up takes up the objective afresh. The live goals are read
     * before they are written: which rung the account is on is the board's answer, not a second opinion
     * formed here (architecture step 4, hysteresis).
     */
    private function holdGoal(AiProfile $profile): void
    {
        $player = app(PlayerServiceFactory::class)->make($profile->player_id);
        $rung = app(GamePhaseMachine::class)->rungPlanets();
        $owned = $player->planets->planetCount();
        $held = $this->goalBoard->active($profile->player_id);

        if ($held === []) {
            $this->goalBoard->commit($profile->player_id, $this->goalBoard->objective(), $rung, progress: $owned);

            return;
        }

        // The intention is the earlier login's; this one only says how far along the account is with
        // it, and the board abandons the goal the moment its target is reached or its window passes.
        foreach ($held as $goal) {
            $this->reaffirm($goal, $rung, $owned);
        }
    }

    /** Re-affirm one goal the account already holds, against what it owns today. */
    private function reaffirm(AiGoal $goal, int $rung, int $owned): void
    {
        $this->goalBoard->commit($goal->player_id, $goal->goal, $rung, progress: $owned);
    }

    private function schedule(AiProfile $profile, AiWorkItem $sessionWorkItem, DecisionTrace $trace): void
    {
        // An account the host no longer has, or one with no planets, has nothing an intent could
        // be spent on: the session records what it decided and stops scheduling (L2), so the
        // planners are never asked to build for it. Asking them anyway built a host player for an
        // account that does not exist, which the host refuses.
        if (!$this->accountStateResolver->resolve($profile->player_id)->schedules()) {
            return;
        }

        // The account's own objective lasts from this login to the next, so it is written where the
        // login's objective is chosen rather than recomputed by each manager (architecture step 4).
        $this->holdGoal($profile);

        // Every case is listed: adding a capability means deciding here where it is executed, and
        // a capability with no executor must not be published to begin with.
        $type = $trace->selected->candidate->type;
        // The unit plan is read here, before the building steps are enqueued, because one order's
        // placement decides whether it happens at all: the first wall of a planet that stands bare
        // beside a walled sibling is priced against the balance those steps spend, so the host
        // refuses it and the planet stays naked session after session (QUAL-003).
        //
        // It is read for every chosen action, not only QueueUnits: the wall candidate is one of a
        // dozen and the economy's habit outranks it in most logins, so waiting for the sessions the
        // engine happens to pick the shipyard left the bare planet naked while the account kept
        // colonising (measured live 2 Oct 2026: 1 planet at zero defence beside a sibling holding
        // 1,746). A login is one wall order whichever action was chosen.
        // The wall's own facility may be refused by the economy in the same login (the planet is saving
        // for its next mine), so the unit plan is asked before the building steps for every chosen
        // action: a bare planet's wall is the one order whose placement decides whether it happens.
        $units = $this->queueableUnitPlanner->plan($profile->player_id);
        $unitsFirst = $units instanceof QueueableUnit && $units->aheadOfEconomy;

        // The marked wall is written before the building steps, because work falls due in the order
        // it was written and same-instant work keeps that order: written after them, the steps have
        // already spent the balance the wall was priced against, the host refuses it, and the planet
        // stands naked beside its walled sibling again on the next login (QUAL-003).
        //
        // A wall placed on a login that chose something else keeps a key of its own, so the session's
        // own action still owns the primary key: a retried session converges on that action, and the
        // wall is one more order in the login rather than the login's objective.
        if ($unitsFirst) {
            $this->scheduleUnits($profile, $sessionWorkItem, $this->clock->now(), $units, $type === AiCandidateActionType::QueueUnits ? '' : ':wall');
        }

        // Every *other* bare sibling takes its wall in the same login: a player clicks through all the
        // naked colonies before logging off, and the invariant reads the account, so the one order the
        // pass above places left the rest at zero for as many logins as the account has planets -- more
        // than a day of its own sessions (QUAL-003: 3 planets at zero beside one holding 1,312 units).
        // Each order keeps a key of its own planet, so a retried session converges on the same set.
        foreach ($this->queueableUnitPlanner->standingDefenceOrders($profile->player_id) as $order) {
            if ($unitsFirst && $units instanceof QueueableUnit && $order->planetId === $units->planetId) {
                continue;
            }

            $this->scheduleUnits($profile, $sessionWorkItem, $this->clock->now(), $order, ':wall:planet:' . $order->planetId);
        }

        // An inbound is not one order. Whichever page the login opened, the account answers it with
        // every response the threat planner named: the fleet that is worth the trip leaves and the
        // stock the raider would otherwise carry off leaves on the hulls kept at home. Each response
        // is placed by the planner that owns that order, and the wall among them is the unit plan
        // above. Written here, ahead of the building steps, because those steps spend the stock the
        // save and the ferry were priced against, and an inbound leaves no second login to place them.
        $this->scheduleThreatResponses($profile, $sessionWorkItem, $type);

        // A player refills the build queues and the lab every login before turning to the shipyard or
        // the fleet; choosing a raid, a ship, nothing at all or answering an inbound fleet must not
        // leave a planet idle until the next session. The refill asks the planner, never the decision
        // trace: a planet is buildable exactly when the planner offers it a step -- the same call the
        // cohort read-out makes -- so asking the trace instead lets the two disagree and the account
        // read idle while it had a legal order to place. A planet the planner offers no step for
        // enqueues nothing, so a login with nothing to spend stays quiet.
        // A Save answers first: its intent is written ahead of these steps, so the fleet still moves
        // before the last order lands.
        // An attacked account saves before it builds: work falls due in the order it was written and
        // same-instant work keeps that order, so the save is written here -- ahead of the building
        // steps -- or the stock it moves has already been spent by them and the fleet stays on the
        // ground. The calm save has no such errand and takes the match arm below, after the builds.
        if ($type === AiCandidateActionType::FleetSave && !$this->isCalmSave($type, $trace)) {
            $this->scheduleFleetSave($profile, $sessionWorkItem, $this->clock->now());
        }

        // A session that decided nothing is a player with no intent to spend and never refills the
        // queues; a session the engine offered no economy step is one with nothing legal to place.
        $economySteps = $this->refillsQueues($type, $trace)
            ? $this->fillQueues($profile, $sessionWorkItem, $this->economyKey($type))
            : 0;

        match ($type) {
            // Build and Research are filled by the pass above under the session's own key, so the
            // refill and the session's objective are one work item.
            AiCandidateActionType::Build, AiCandidateActionType::Research => null,
            // The shipyard gets what the buildings leave, so its order waits until they are placed:
            // the host cancels a building it cannot pay for, and a ship order placed first would cause it.
            // A marked wall is already written above; the repeat finds that row and leaves its time.
            AiCandidateActionType::QueueUnits => $this->scheduleShipyard($profile, $sessionWorkItem, $this->clock->now()->addSeconds($economySteps * self::SECONDS_BETWEEN_PLANETS), $units),
            AiCandidateActionType::Colonize => $this->scheduleColony($profile, $sessionWorkItem),
            AiCandidateActionType::Expedition => $this->scheduleExpedition($profile, $sessionWorkItem),
            // The ferry moves stock the buildings were priced against, so it waits for them the way
            // the shipyard and the save do: a transfer written at the session's own instant runs
            // between the second and third planet's build and the host then refuses those builds,
            // leaving the planets idle until the next login.
            AiCandidateActionType::Transfer => $this->scheduleTransfer($profile, $sessionWorkItem, $this->clock->now()->addSeconds($economySteps * self::SECONDS_BETWEEN_PLANETS)),
            AiCandidateActionType::Recycle => $this->scheduleRecycle($profile, $sessionWorkItem),
            AiCandidateActionType::FleetSave => $this->scheduleFleetSave($profile, $sessionWorkItem, $this->clock->now()->addSeconds($economySteps * self::SECONDS_BETWEEN_PLANETS), '', $this->isCalmSave($type, $trace) ? $trace->perception->upcomingAbsenceMinutes : null),
            AiCandidateActionType::Recall => $this->scheduleRecall($profile, $sessionWorkItem),
            AiCandidateActionType::Spy => $this->scheduleSpy($profile, $sessionWorkItem),
            AiCandidateActionType::Raid => $this->scheduleRaid($profile, $sessionWorkItem, $trace),
            AiCandidateActionType::ThrottleMine => $this->scheduleMinePercent($profile, $sessionWorkItem),
            AiCandidateActionType::Phalanx => $this->schedulePhalanx($profile, $sessionWorkItem),
            AiCandidateActionType::Defend => $this->scheduleDefend($profile, $sessionWorkItem),
            AiCandidateActionType::Trade => $this->scheduleTrade($profile, $sessionWorkItem),
            AiCandidateActionType::Relocate => $this->scheduleRelocation($profile, $sessionWorkItem),
            AiCandidateActionType::JumpGate => $this->scheduleJumpGate($profile, $sessionWorkItem),
            AiCandidateActionType::Missile => $this->scheduleMissile($profile, $sessionWorkItem),
            AiCandidateActionType::DoNothing => $this->recordQuietDecision($profile, $trace),
        };

        // The war fleet's own order, on a login that chose something else: a player with a war chest
        // keeps the shipyard busy whichever page they opened, so the surplus buys the best hull the
        // yard can build without waiting for the sessions the engine happens to pick the shipyard. The
        // fleet is asked of the planner directly rather than read from the plan above, which only
        // carries it when no other role on any planet wanted anything: a login is almost always
        // consumed by a probe, a colony ship or a wall, so the plan the schedule was handed never held
        // a hull and the account bought none (measured live: 160 QueueUnits orders in half an hour and
        // no hull above the median military hull anywhere in the cohort). It is written after the
        // building steps, which are priced against the balance first, and a session that decided
        // nothing is one with no intent to spend.
        $capital = $units instanceof QueueableUnit && $units->surplusSpend
            ? $units
            : $this->queueableUnitPlanner->capitalFleetOrder($profile->player_id);

        // A player whose yard still holds an unfinished order does not click buy again: the surplus
        // hull is a habit, not an errand, and a login that ordered on top of an unfinished batch
        // buried the shipyard under identical hulls (measured live 3 Oct 2026: 425 unit orders in
        // half an hour against 1,057 buildings). The wall orders above are untouched: keeping a
        // planet alive is what the yard is for.
        if ($capital !== null
            && ! $this->shipyardsBusy($profile->player_id)
            && ! ($type === AiCandidateActionType::QueueUnits && $units === $capital)
            && $type !== AiCandidateActionType::DoNothing) {
            $this->scheduleUnits($profile, $sessionWorkItem, $this->clock->now()->addSeconds($economySteps * self::SECONDS_BETWEEN_PLANETS), $capital, ':capital');
        }

        $this->runManagers($profile, $sessionWorkItem, $trace, $type, $this->clock->now()->addSeconds($economySteps * self::SECONDS_BETWEEN_PLANETS));
    }

    /**
     * Every manager acts on every login (architecture step 3). The engine's selection above is the login's
     * first errand; the managers below then do what a player does in the same login: send every raid the
     * reports and the slots allow, probe a batch of targets, ferry, keep the expedition slot busy, colonise
     * and pick up debris. Arbitration is fixed priority over the shared fleet slots, and the ships each order
     * takes are claimed so the next order plans from what is left. An idle login (DoNothing) is the humaniser's
     * moment and stays idle. How much each manager does is the archetype's doctrine (`managers.yaml`).
     */
    private function runManagers(AiProfile $profile, AiWorkItem $sessionWorkItem, DecisionTrace $trace, AiCandidateActionType $type, CarbonImmutable $afterEconomy): void
    {
        if ($type === AiCandidateActionType::DoNothing) {
            return;
        }

        $doctrine = app(ManagerDoctrine::class);
        $archetype = $profile->archetype;
        // The account's phase once per login: the numbers below are the archetype's, scaled by how far the
        // account has come (architecture step 4).
        $phase = app(GamePhaseMachine::class)->of(app(PlayerServiceFactory::class)->make($profile->player_id));
        $slots = app(FleetSlots::class)->free($profile->player_id) - $doctrine->int($archetype, 'keep_slots_free', $phase);

        // Military: raid waves on every report the planner approves, the selected one already placed.
        $waves = $doctrine->int($archetype, 'raid_waves', $phase) - ($type === AiCandidateActionType::Raid ? 1 : 0);
        $selectedReport = (int) ($trace->selected->candidate->parameters['report_id'] ?? 0);
        foreach ($trace->candidates as $scored) {
            if ($waves <= 0 || $slots <= 0) {
                break;
            }
            $candidate = $scored->candidate;
            $reportId = (int) ($candidate->parameters['report_id'] ?? 0);
            if ($candidate->type !== AiCandidateActionType::Raid || $reportId === 0 || $reportId === $selectedReport) {
                continue;
            }
            if ($this->placeRaid($profile, $sessionWorkItem, $reportId, ':raid:' . $reportId)) {
                $waves--;
                $slots--;
            }
        }

        // Missiles take no fleet slot.
        if ($doctrine->bool($archetype, 'missiles') && $type !== AiCandidateActionType::Missile) {
            $this->asManager(':missile', fn () => $this->scheduleMissile($profile, $sessionWorkItem));
        }

        // Intel: a batch of probes, each to a different target (the spy planner skips targets already promised).
        $probes = $doctrine->int($archetype, 'probes_per_login', $phase) - ($type === AiCandidateActionType::Spy ? 1 : 0);
        $probed = 0;
        for ($i = 0; $i < $probes && $slots > 0; $i++) {
            if (!$this->asManager(':spy:' . $i, fn () => $this->scheduleSpy($profile, $sessionWorkItem))) {
                break;
            }
            $slots--;
            $probed++;
        }

        // The rest of the login, one order each while slots remain.
        $errands = [
            'transfer' => [AiCandidateActionType::Transfer, fn () => $this->scheduleTransfer($profile, $sessionWorkItem, $afterEconomy)],
            'expedition' => [AiCandidateActionType::Expedition, fn () => $this->scheduleExpedition($profile, $sessionWorkItem)],
            'colony' => [AiCandidateActionType::Colonize, fn () => $this->scheduleColony($profile, $sessionWorkItem)],
            'recycle' => [AiCandidateActionType::Recycle, fn () => $this->scheduleRecycle($profile, $sessionWorkItem)],
        ];
        foreach ($errands as $key => [$errandType, $schedule]) {
            if ($slots <= 0) {
                break;
            }
            if ($type === $errandType || !$doctrine->bool($archetype, $key)) {
                continue;
            }
            if ($this->asManager(':' . $key, $schedule)) {
                $slots--;
            }
        }

        // Continuation (architecture step 5): the probes just sent come back in a minute or two, and a player
        // reads them and raids inside the same login instead of waiting for the next one.
        $minutes = $doctrine->int($archetype, 'continuation_minutes', $phase);
        if ($probed > 0 && $minutes > 0) {
            $this->asManager(':wave', fn () => $this->enqueue($profile, $sessionWorkItem, AiWorkKind::RaidWave, [
                self::PAYLOAD_PLANET_ID => (int) (Planet::query()->where('user_id', $profile->player_id)->orderBy('id')->value('id') ?? 0),
                'since' => $this->clock->now()->timestamp,
                self::PAYLOAD_REASON => 'raid_wave',
            ], $this->clock->now()->addMinutes($minutes)));
        }
    }

    /** Runs one manager's schedule call under its own key; true when it wrote an order. */
    private function asManager(string $suffix, \Closure $schedule): bool
    {
        $this->managerSuffix = $suffix;
        $this->enqueued = false;

        try {
            $schedule();
        } finally {
            $this->managerSuffix = '';
        }

        return $this->enqueued;
    }

    /**
     * One raid on one report, planned against the report and the ships this login has not yet promised,
     * and its ships claimed for the rest of the login.
     */
    private function placeRaid(AiProfile $profile, AiWorkItem $sessionWorkItem, int $reportId, string $keySuffix): bool
    {
        $plan = $this->raidPlanner->plan($profile->player_id, $reportId);
        if (!$plan instanceof QueueableRaid) {
            return false;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Raid, [
            self::PAYLOAD_PLANET_ID => $plan->originPlanetId,
            self::PAYLOAD_TARGET_GALAXY => $plan->targetGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->targetSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->targetPosition,
            self::PAYLOAD_TARGET_TYPE => $plan->targetType,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_LAUNCH_UNITS => $plan->launchUnits,
            self::PAYLOAD_REASON => 'raid:' . $reportId,
        ], null, $keySuffix);
        app(LoginReservations::class)->claim($plan->originPlanetId, $plan->launchUnits);

        return true;
    }

    /**
     * The shipyard arm of a login: the plan's surplus hull, or nothing while the yard already holds an
     * unfinished order. The hull is what the account buys when no role on any planet wanted anything --
     * a habit rather than an errand -- so a login that finds the yard busy leaves it alone instead of
     * stacking a second identical batch behind the first (ARB-001: units ordered on eight of six logins).
     * The wall, the cargo and the probes above keep their orders: those are the errands.
     */
    private function scheduleShipyard(AiProfile $profile, AiWorkItem $sessionWorkItem, CarbonImmutable $dueAt, ?QueueableUnit $plan): void
    {
        if ($plan instanceof QueueableUnit && $plan->surplusSpend && $this->shipyardsBusy($profile->player_id)) {
            return;
        }

        $this->scheduleUnits($profile, $sessionWorkItem, $dueAt, $plan);
    }

    /** Whether any of the player's yards holds an order the host has not finished building yet. */
    private function shipyardsBusy(int $playerId): bool
    {
        $planets = Planet::query()->where('user_id', $playerId)->pluck('id');

        return UnitQueue::query()->whereIn('planet_id', $planets)->where('processed', 0)->exists();
    }

    private function isCalmSave(AiCandidateActionType $type, DecisionTrace $trace): bool
    {
        return $type === AiCandidateActionType::FleetSave && $trace->perception->inboundFleets === [];
    }

    /** Whether this session refills the build queues and the lab before its own objective. */
    private function refillsQueues(AiCandidateActionType $type, DecisionTrace $trace): bool
    {
        return $type !== AiCandidateActionType::DoNothing && $this->economyOffered($trace);
    }

    /** Only what the engine offered this session, so legality and persona policy stay its own. */
    private function economyOffered(DecisionTrace $trace): bool
    {
        foreach ($trace->candidates as $scored) {
            if (in_array($scored->candidate->type, [AiCandidateActionType::Build, AiCandidateActionType::Research], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The key the queue refill owns. Build and Research own the session's own key, so the refill is
     * the session's objective; every other action takes a key of its own, leaving the objective's key
     * to the objective.
     */
    private function economyKey(AiCandidateActionType $type): string
    {
        return match ($type) {
            AiCandidateActionType::Build, AiCandidateActionType::Research => '',
            default => ':economy',
        };
    }

    /**
     * One intent per queue the planner found free and payable: a building per planet and one
     * technology. Legality is re-asked here rather than trusted from the decision, and the step the
     * planner verified travels with the intent, so the schedule and the executor name one objective.
     * The first step keeps the session's own key, so a retried session converges on the same work.
     */
    private function fillQueues(AiProfile $profile, AiWorkItem $sessionWorkItem, string $keyPrefix): int
    {
        // The planner's steps, or a choice policy's answer to the same candidates (plan/rl; off by default).
        $steps = app(DecideAiEconomyStepsAction::class)->handle($profile->player_id, 'work:' . $sessionWorkItem->id);
        foreach ($steps as $index => $step) {
            [$kind, $payload] = $step instanceof QueueableResearch
                ? [AiWorkKind::QueueResearch, [self::PAYLOAD_RESEARCH_ID => $step->researchId]]
                : [AiWorkKind::BuildFirstBuilding, [self::PAYLOAD_BUILDING_ID => $step->buildingId]];

            $this->enqueue($profile, $sessionWorkItem, $kind, [
                self::PAYLOAD_PLANET_ID => $step->planetId,
                ...$payload,
                self::PAYLOAD_REASON => $step->reason,
            ], $this->clock->now()->addSeconds($index * self::SECONDS_BETWEEN_PLANETS), $keyPrefix . ($index === 0 ? '' : ':' . $index));
        }

        return count($steps);
    }

    /**
     * W8-L7: a quiet session leaves a counter naming why nothing else was available, so the
     * review loop can read "why is the population quiet today" from the same artifact every
     * other refusal uses. No new table.
     */
    private function recordQuietDecision(AiProfile $profile, DecisionTrace $trace): void
    {
        app(RecordAiStopReasonAction::class)->handle(AiStopReason::QuietDecision, [
            'player_id' => $profile->player_id,
            'candidates' => count($trace->candidates),
            'rejections' => count($trace->rejections),
        ]);
    }

    /**
     * A unit the plan approved travels with the intent, hull and amount.
     *
     * Re-deciding at execution time would let a published capability, the schedule and the queued
     * unit name three different objectives, which is how an account ends up building combat ships
     * while it claims to be assembling cargo.
     */
    private function scheduleUnits(AiProfile $profile, AiWorkItem $sessionWorkItem, CarbonImmutable $dueAt, ?QueueableUnit $plan, string $keySuffix = ''): void
    {
        if (!$plan instanceof QueueableUnit) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::QueueUnits, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_UNIT_ID => $plan->unitId,
            self::PAYLOAD_AMOUNT => $plan->amount,
            self::PAYLOAD_REASON => $plan->reason,
        ], $dueAt, $keySuffix);
    }

    /**
     * The same for a colony the plan approved: the origin planet and the empty
     * slot travel with the intent, so the published capability and the launched
     * mission name the same destination.
     */
    private function scheduleColony(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableColonyPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableColony) {
            return;
        }

        // The colony ship comes before the mission: a dispatch with no ship at the origin is refused
        // on every login and the ship is never built.
        $ship = $this->queueableUnitPlanner->colonyShipFor($profile->player_id, $plan->planetId);
        if ($ship instanceof QueueableUnit) {
            $this->scheduleUnits($profile, $sessionWorkItem, $this->clock->now(), $ship);

            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Colonize, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_GALAXY => $plan->galaxy,
            self::PAYLOAD_SYSTEM => $plan->system,
            self::PAYLOAD_POSITION => $plan->position,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_REASON => 'colony:' . $plan->galaxy . ':' . $plan->system . ':' . $plan->position,
        ]);
    }

    /**
     * The same for an expedition the plan approved: the origin body and the
     * slot-16 coordinate travel with the intent, re-planned so a full slot or a
     * missing disposable ship never becomes work.
     */
    private function scheduleExpedition(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableExpeditionPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableExpedition) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Expedition, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_GALAXY => $plan->galaxy,
            self::PAYLOAD_SYSTEM => $plan->system,
            self::PAYLOAD_POSITION => $plan->position,
            self::PAYLOAD_REASON => 'expedition:' . $plan->galaxy . ':' . $plan->system . ':' . $plan->position,
        ]);
    }

    /**
     * The same for a transfer the plan approved: source, target and the shipment travel with the
     * intent, so the ferry funds the body the session saw rather than a re-decided shortfall.
     */
    private function scheduleTransfer(AiProfile $profile, AiWorkItem $sessionWorkItem, CarbonImmutable $dueAt): void
    {
        $plan = $this->queueableTransferPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableTransfer) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Transfer, [
            self::PAYLOAD_SOURCE_PLANET_ID => $plan->sourcePlanetId,
            self::PAYLOAD_TARGET_PLANET_ID => $plan->targetPlanetId,
            self::PAYLOAD_METAL => $plan->metal,
            self::PAYLOAD_CRYSTAL => $plan->crystal,
            self::PAYLOAD_DEUTERIUM => $plan->deuterium,
            self::PAYLOAD_REASON => 'transfer:' . $plan->sourcePlanetId . ':' . $plan->targetPlanetId,
        ], $dueAt);
    }

    /**
     * The same for a debris field the plan approved: the origin body and the field
     * coordinate travel with the intent, so the harvest goes where the session saw.
     */
    private function scheduleRecycle(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableRecyclePlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableRecycle) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Recycle, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_TARGET_GALAXY => $plan->targetGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->targetSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->targetPosition,
            self::PAYLOAD_TARGET_TYPE => $plan->targetType,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_REASON => 'recycle:' . $plan->targetGalaxy . ':' . $plan->targetSystem . ':' . $plan->targetPosition,
        ]);
    }

    /**
     * The same for a fleetsave the plan approved: the threatened planet and the
     * destination travel with the intent, so the save moves the fleet to the
     * planet the session saw rather than a re-decided one.
     */
    private function scheduleFleetSave(AiProfile $profile, AiWorkItem $sessionWorkItem, CarbonImmutable $dueAt, string $keySuffix = '', ?int $absenceMinutes = null): void
    {
        $plan = $this->queueableFleetSavePlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableFleetSave) {
            return;
        }

        // A save that is not taken now and then is what a person does; the loss is counted so the
        // cohort read can see it (AUTH_SAVE). The draw belongs to the save occasion, not to the
        // login: the calm save before a night is drawn against the absence the account is leaving
        // for, so its own night save repeats the same judgement instead of flipping from one login
        // to the next and vanishing at some hours of the day (FLEET-001).
        if ($this->saveFailurePolicy->shouldSkip((int) $profile->random_seed, $absenceMinutes ?? (int) $sessionWorkItem->id) !== null) {
            app(RecordAiStopReasonAction::class)->handle(AiStopReason::SaveLost, ['player_id' => $profile->player_id]);

            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::FleetSave, [
            self::PAYLOAD_PLANET_ID => $plan->originPlanetId,
            self::PAYLOAD_DESTINATION_PLANET_ID => $plan->destinationPlanetId,
            self::PAYLOAD_SHADOW_DESTINATION_PLANET_ID => $plan->shadowDestinationPlanetId,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_TARGET_GALAXY => $plan->harvestGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->harvestSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->harvestPosition,
            self::PAYLOAD_JUMP_GATE_PLANET_ID => $plan->jumpGatePlanetId,
            self::PAYLOAD_SPEED => $plan->speed,
            self::PAYLOAD_REASON => 'fleetsave',
        ], $dueAt, $keySuffix);
    }

    /**
     * One login's answer to an inbound, one order per response the threat planner named. The engine's
     * own selection already owns the session's key, so a response the engine chose is not placed twice:
     * the match arm writes that one under the objective's own key and this pass leaves it alone.
     */
    private function scheduleThreatResponses(AiProfile $profile, AiWorkItem $sessionWorkItem, AiCandidateActionType $type): void
    {
        $plan = $this->threatPlan($profile);
        if (! $plan->underAttack) {
            return;
        }

        if ($type !== AiCandidateActionType::FleetSave && $plan->holdsAnywhere(AiThreatResponse::EvacuateFleet)) {
            $this->scheduleFleetSave($profile, $sessionWorkItem, $this->clock->now(), ':threat');
        }

        if ($type === AiCandidateActionType::Transfer) {
            return;
        }

        foreach ($plan->planets(AiThreatResponse::EvacuateResources) as $planetId) {
            $this->scheduleEvacuation($profile, $sessionWorkItem, $plan->evacuation($planetId));
        }
    }

    /**
     * What this login answers an inbound with, decided once for the one arm that reads it. The type is
     * the planner's own plan, so the schedule and the planner cannot disagree about its shape.
     */
    private function threatPlan(AiProfile $profile): ThreatResponsePlan
    {
        return $this->threatResponsePlanner->plan($profile->player_id);
    }

    /**
     * The ferry one body's evacuation response flies: the shipment the ferry planner priced travels
     * with the intent, so the schedule and the dispatch name the same pile. Each body keeps a key of
     * its own, so a retried session converges on the same set of ferries.
     */
    private function scheduleEvacuation(AiProfile $profile, AiWorkItem $sessionWorkItem, ?QueueableTransfer $plan): void
    {
        if (!$plan instanceof QueueableTransfer) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Transfer, [
            self::PAYLOAD_SOURCE_PLANET_ID => $plan->sourcePlanetId,
            self::PAYLOAD_TARGET_PLANET_ID => $plan->targetPlanetId,
            self::PAYLOAD_METAL => $plan->metal,
            self::PAYLOAD_CRYSTAL => $plan->crystal,
            self::PAYLOAD_DEUTERIUM => $plan->deuterium,
            self::PAYLOAD_REASON => 'threat:evacuate:' . $plan->sourcePlanetId,
        ], $this->clock->now(), ':threat:planet:' . $plan->sourcePlanetId);
    }

    /**
     * A recall names no target: the parked deployment is re-planned here, and
     * only a real in-flight save becomes work. The planet travels with the
     * intent so the dispatch advances the body the deployment left from.
     */
    private function scheduleRecall(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableFleetSavePlanner->recallPlan($profile->player_id);
        if (!$plan instanceof QueueableRecall) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Recall, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_REASON => 'recall',
        ], CarbonImmutable::createFromTimestamp($plan->recallAt));
    }

    /**
     * The same for an espionage target the plan approved: the origin planet and
     * the target travel with the intent, so the probe goes where the session saw.
     */
    private function scheduleSpy(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableSpyPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableSpy) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Spy, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_TARGET_GALAXY => $plan->targetGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->targetSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->targetPosition,
            self::PAYLOAD_TARGET_TYPE => $plan->targetType,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_PROBE_COUNT => $plan->probeCount,
            self::PAYLOAD_REASON => 'spy:' . $plan->targetGalaxy . ':' . $plan->targetSystem . ':' . $plan->targetPosition,
        ]);
    }

    /**
     * A phalanx scan of one raid target: the moon and the target travel with the intent,
     * so the scan runs against what the session saw.
     */
    private function scheduleRelocation(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableRelocationPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableRelocation) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Relocate, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_GALAXY => $plan->galaxy,
            self::PAYLOAD_SYSTEM => $plan->system,
            self::PAYLOAD_POSITION => $plan->position,
            self::PAYLOAD_REASON => 'relocate:' . $plan->planetId,
        ]);
    }

    private function scheduleTrade(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableTradePlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableTrade) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Trade, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            'give_resource' => $plan->giveResource,
            'receive_resource' => $plan->receiveResource,
            self::PAYLOAD_AMOUNT => $plan->giveAmount,
            self::PAYLOAD_REASON => 'trade:' . $plan->planetId . ':' . $plan->giveResource,
        ]);
    }

    private function scheduleJumpGate(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableJumpGatePlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableJumpGate) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::JumpGate, [
            self::PAYLOAD_SOURCE_PLANET_ID => $plan->sourceMoonId,
            self::PAYLOAD_TARGET_PLANET_ID => $plan->targetMoonId,
            self::PAYLOAD_REASON => 'jump_gate:' . $plan->sourceMoonId,
        ]);
    }

    private function scheduleMissile(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = app(\Modules\AI\Domain\Decision\QueueableMissilePlanner::class)->plan($profile->player_id);
        if (!$plan instanceof \Modules\AI\Domain\Decision\QueueableMissile) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Missile, [
            self::PAYLOAD_SOURCE_PLANET_ID => $plan->originPlanetId,
            self::PAYLOAD_TARGET_GALAXY => $plan->targetGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->targetSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->targetPosition,
            self::PAYLOAD_TARGET_TYPE => $plan->targetType,
            self::PAYLOAD_AMOUNT => $plan->missiles,
            self::PAYLOAD_REASON => 'missile:' . $plan->targetGalaxy . ':' . $plan->targetSystem . ':' . $plan->targetPosition,
        ]);
    }

    private function scheduleDefend(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableDefendPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableDefend) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Defend, [
            self::PAYLOAD_SOURCE_PLANET_ID => $plan->sourcePlanetId,
            self::PAYLOAD_TARGET_PLANET_ID => $plan->targetPlanetId,
            self::PAYLOAD_REASON => 'defend:' . $plan->targetPlanetId,
        ]);
    }

    private function schedulePhalanx(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueablePhalanxPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueablePhalanx) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Phalanx, [
            self::PAYLOAD_PLANET_ID => $plan->moonPlanetId,
            self::PAYLOAD_TARGET_PLANET_ID => $plan->targetPlanetId,
            self::PAYLOAD_REASON => 'phalanx:' . $plan->targetPlanetId,
        ]);
    }

    /**
     * A mine-percentage change travels as its own intent: the planet, the mine
     * and the percentage the planner derived from the host's own numbers.
     */
    private function scheduleMinePercent(AiProfile $profile, AiWorkItem $sessionWorkItem): void
    {
        $plan = $this->queueableMinePercentPlanner->plan($profile->player_id);
        if (!$plan instanceof QueueableMinePercent) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::SetMinePercent, [
            self::PAYLOAD_PLANET_ID => $plan->planetId,
            self::PAYLOAD_BUILDING_ID => $plan->buildingId,
            self::PAYLOAD_PERCENTAGE => $plan->percentage,
            self::PAYLOAD_REASON => $plan->reason,
        ]);
    }

    /**
     * The same for a raid the session selected: the report the decision named is
     * re-planned through the profit test and bashing limit, and the target that
     * passed travels with the intent.
     */
    private function scheduleRaid(AiProfile $profile, AiWorkItem $sessionWorkItem, DecisionTrace $trace): void
    {
        $reportId = (int) ($trace->selected->candidate->parameters['report_id'] ?? 0);
        if ($reportId === 0) {
            return;
        }

        $plan = $this->raidPlanner->plan($profile->player_id, $reportId);
        if (!$plan instanceof QueueableRaid) {
            return;
        }

        $this->enqueue($profile, $sessionWorkItem, AiWorkKind::Raid, [
            self::PAYLOAD_PLANET_ID => $plan->originPlanetId,
            self::PAYLOAD_TARGET_GALAXY => $plan->targetGalaxy,
            self::PAYLOAD_TARGET_SYSTEM => $plan->targetSystem,
            self::PAYLOAD_TARGET_POSITION => $plan->targetPosition,
            self::PAYLOAD_TARGET_TYPE => $plan->targetType,
            self::PAYLOAD_MISSION_TYPE => $plan->missionType,
            self::PAYLOAD_LAUNCH_UNITS => $plan->launchUnits,
            self::PAYLOAD_REASON => 'raid:' . $reportId,
        ]);
        app(LoginReservations::class)->claim($plan->originPlanetId, $plan->launchUnits);
    }
}
