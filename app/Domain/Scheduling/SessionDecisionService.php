<?php

namespace Modules\AI\Domain\Scheduling;

use Carbon\CarbonImmutable;
use Modules\AI\Actions\ConsultCampaignDecisionAction;
use Modules\AI\Actions\DecideAiErrandAction;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Lifecycle\AccountStateResolver;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Domain\Routine\RoutineProfile;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BuildingQueue;
use OGame\Models\FleetMission;
use OGame\Models\ResearchQueue;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Symfony\Component\Yaml\Yaml;

/**
 * Records a deterministic session decision and schedules exactly one future
 * session. Action execution is deliberately outside this service: unavailable
 * capabilities remain traceable intents rather than requiring AI-specific host
 * contracts.
 */
class SessionDecisionService
{
    private const TRACE_RETENTION_DAYS = 30;

    /** Module root relative, so how long a shortfall is worth waiting for is loaded by name (Gate 1). */
    private const PACING_POLICY = '/resources/behavior/session-pacing.yaml';

    /** SP3: the account arrives this many seconds after a material event, right-skewed toward short. */
    private const MATERIAL_EVENT_ARRIVAL_MIN_SECONDS = 30;

    private const MATERIAL_EVENT_ARRIVAL_MAX_SECONDS = 300;

    /** @var array<string, mixed>|null */
    private static ?array $policy = null;

    public function __construct(
        private PlayerPerceptionBuilder $playerPerceptionBuilder,
        private SessionPlanner $sessionPlanner,
        private NextDueTimeCalculator $nextDueTimeCalculator,
        private DecisionEngine $decisionEngine,
        private ConsultCampaignDecisionAction $consultCampaignDecision,
        private QueueableBuildingPlanner $buildingPlanner,
        private AiClock $clock,
        private AccountStateResolver $accountStateResolver,
        private RandomSource $randomSource,
    ) {
    }

    public function run(AiProfile $profile, AiWorkItem $workItem): DecisionTrace
    {
        $now = $this->clock->now();
        $routine = RoutineProfile::fromAiProfile($profile);
        $schedule = $this->scheduleFor($profile, $routine, $now);

        // The session plan is computed before the decision so the decision can
        // see the absence this session is about to enter (V6): a proactive save
        // is offered only when the gap until the next session is a real one.
        $plan = $this->sessionPlanner->plan($profile, $now, $schedule->generation);
        // Carbon 3 signs its differences: measured from the session's end forward to the next login, the gap is positive.
        // The login that really comes next, not the routine's: an accelerated universe is back in seconds,
        // and a save before an absence that never happens only parks a fleet that should be fighting.
        $upcomingAbsenceMinutes = max(0, (int) $plan->sessionEndsAt->diffInMinutes($this->nextDueTimeCalculator->fromSession($plan, $now)));

        $perception = $this->playerPerceptionBuilder->build($profile->player_id, $upcomingAbsenceMinutes);
        $decisionKey = 'work:' . $workItem->id . ':generation:' . $schedule->generation;
        // The login's errand: the engine's selection, or a choice policy's answer over the same ranked actions (plan/rl; off by default).
        $trace = app(DecideAiErrandAction::class)->handle($profile, $perception, $this->decisionEngine->decide($profile, $perception, $decisionKey), $decisionKey);

        // A material campaign event (a new phase, a held stronghold) consults once here and may
        // nudge the ranking before the final selection; off by default, so an ordinary session
        // makes no provider call and its recorded decision is unchanged.
        $trace = $this->consultCampaignDecision->handle($profile, $trace, $decisionKey);

        $this->recordDecisionTrace($profile, $workItem, $trace, $now);

        // An account with no planets, or one the host no longer has, has nothing
        // to come back to. The session still records what it decided, and the
        // chain stops instead of deciding nothing forever -- and stopping it is
        // the whole difference between an idle account and a stated one.
        if (!$this->accountStateResolver->resolve($profile->player_id)->schedules()) {
            return $trace;
        }

        $nextDueAt = $this->nextDueTimeCalculator->fromSession($plan, $now);

        // An accelerated universe still sleeps: a session that would land in the dark period waits
        // for the routine's wake, or every account reads as round-the-clock (AUTH_UPTIME).
        if (!$this->sessionPlanner->isAwake($profile, $nextDueAt)) {
            $nextDueAt = $plan->nextDueAt;
        }

        // V2: a hostile inbound schedules the reaction wake, so the account reacts inside the
        // window before impact instead of at its next ordinary session.
        if ($perception->reactionWakeAt !== null) {
            $reactionWakeAt = CarbonImmutable::createFromTimestamp($perception->reactionWakeAt);
            if ($reactionWakeAt->greaterThan($now) && $reactionWakeAt->lessThan($nextDueAt) && $this->wakesForReaction($profile, $reactionWakeAt)) {
                $nextDueAt = $reactionWakeAt;
            }
        }

        // SP3: wake at the next material event inside the waking window (a build, research or
        // fleet landing) instead of the full routine gap; the routine session stays the bound.
        $nextDueAt = $this->nextMaterialEventWake($profile, $perception, $now, $nextDueAt);

        // An account that sleeps still lives: a session is never scheduled past the host's own
        // inactive-deletion threshold, so a long persona-shaped absence reads as a holiday,
        // never as an abandoned account the host would purge.
        $nextDueAt = $this->livenessFloor($now, $nextDueAt);

        $nextGeneration = $schedule->generation + 1;

        $this->scheduleSuccessor($profile, $routine, $schedule, $plan->sessionEndsAt, $nextDueAt, $nextGeneration, $now);

        return $trace;
    }

    /**
     * Whether a hostile inbound brings the account back before its next ordinary session.
     *
     * A player at the keyboard answers an attack coming in; one who is asleep reads the report with the
     * next morning's coffee. The look therefore only counts inside the account's own waking window: a
     * session taken in the dark period is a login the account never makes, and the host counts the
     * hours of the day an account is active (AUTH_UPTIME) -- the routine already spans most of them, so
     * a single session in the night is the whole difference between a player and a machine. The attack
     * is read at the next waking session instead.
     */
    private function wakesForReaction(AiProfile $profile, CarbonImmutable $wakeAt): bool
    {
        return $this->sessionPlanner->isAwake($profile, $wakeAt);
    }

    private function scheduleFor(AiProfile $profile, RoutineProfile $routine, CarbonImmutable $now): AiSchedule
    {
        return AiSchedule::query()->firstOrCreate(
            ['player_id' => $profile->player_id],
            ['timezone' => $routine->timezone, 'next_due_at' => $now, 'generation' => 1],
        );
    }

    /**
     * The first material event the account would be awake for, or the routine next-due when
     * every event lands in the dark period. Each candidate is the host's own finish/arrival
     * time plus a right-skewed arrival delay, so the account checks shortly after the event
     * rather than sitting on it exactly (SP3).
     */
    private function nextMaterialEventWake(AiProfile $profile, PerceptionSnapshot $perception, CarbonImmutable $now, CarbonImmutable $routineNextDue): CarbonImmutable
    {
        $next = $routineNextDue;
        $seed = $profile->random_seed;

        foreach ($this->materialEventEtas($profile, $perception, $now) as $eta) {
            $candidate = CarbonImmutable::createFromTimestamp($eta)
                ->addSeconds($this->materialArrivalDelay($seed, (string) $eta));

            if ($candidate->greaterThan($now) && $candidate->lessThan($next) && $this->sessionPlanner->isAwake($profile, $candidate)) {
                $next = $candidate;
            }
        }

        return $next;
    }

    /** @return list<int> */
    private function materialEventEtas(AiProfile $profile, PerceptionSnapshot $perception, CarbonImmutable $now): array
    {
        $nowTimestamp = $now->getTimestamp();
        $planetIds = array_column($perception->planets, 'id');
        $etas = [];

        if ($planetIds !== []) {
            $buildingFinish = BuildingQueue::query()
                ->whereIn('planet_id', $planetIds)
                ->where('processed', 0)
                ->where('time_end', '>', $nowTimestamp)
                ->min('time_end');

            if ($buildingFinish !== null) {
                $etas[] = (int) $buildingFinish;
            }

            $researchFinish = ResearchQueue::query()
                ->whereIn('planet_id', $planetIds)
                ->where('processed', 0)
                ->where('time_end', '>', $nowTimestamp)
                ->min('time_end');

            if ($researchFinish !== null) {
                $etas[] = (int) $researchFinish;
            }
        }

        $fleetArrival = FleetMission::query()
            ->where('user_id', $profile->player_id)
            ->where('processed', 0)
            ->where('canceled', 0)
            ->where('time_arrival', '>', $nowTimestamp)
            ->min('time_arrival');

        if ($fleetArrival !== null) {
            $etas[] = (int) $fleetArrival;
        }

        foreach ($perception->inboundFleets as $inbound) {
            $etas[] = (int) $inbound['time_arrival'];
        }

        $affordable = $this->affordabilityEta($profile, $perception, $nowTimestamp);
        if ($affordable !== null) {
            $etas[] = $affordable;
        }

        return $etas;
    }

    /**
     * When the first planet that is short of its next economy step can pay for it.
     *
     * A player who cannot afford the upgrade they want yet does not wait for their next ordinary
     * login: they come back when the resources have arrived, which at universe speed is a few
     * minutes. Without it a login that could not pay for anything left every planet's build queue
     * empty until the routine gap -- the account reads as an idle economy whether or not it is
     * saving (ECON-001: `savingFor()` is the planner's own answer, so no affordability rule and no
     * object is restated here).
     */
    private function affordabilityEta(AiProfile $profile, PerceptionSnapshot $perception, int $nowTimestamp): ?int
    {
        $player = app(PlayerServiceFactory::class)->make($profile->player_id, true);
        $earliest = null;

        foreach ($perception->planets as $observed) {
            $planet = $this->ownedPlanet($player, (int) ($observed['id'] ?? 0));
            if ($planet === null) {
                continue;
            }

            // The planner reads live production to price its step, and the session's observation
            // carries only balances: a stale income would put the arrival at the wrong instant.
            $planet->updateResources(false);
            $planet->updateResourceProductionStats(false);

            $eta = $this->planetAffordabilityEta($planet, $profile, $nowTimestamp);
            if ($eta !== null && ($earliest === null || $eta < $earliest)) {
                $earliest = $eta;
            }
        }

        return $earliest;
    }

    /**
     * The instant this planet's next economy step becomes payable, or null when it is not saving for
     * one, can already pay for it, or can never earn it.
     */
    private function planetAffordabilityEta(PlanetService $planet, AiProfile $profile, int $nowTimestamp): ?int
    {
        // The arrival is the planner's own answer, so the wait and the purchase cannot disagree: the
        // account is awake for the step the queue would take.
        $eta = $this->buildingPlanner->savingEta($planet, $profile, CarbonImmutable::createFromTimestamp($nowTimestamp));

        return $eta === null ? null : max($eta->getTimestamp(), $nowTimestamp + $this->shortfallWakeFloorSeconds());
    }

    /**
     * The soonest the account comes back when the only reason is a step it cannot pay for yet (PACE-001).
     *
     * The arrival itself is arithmetic, and at an accelerated universe speed it lands seconds after this
     * login: the account wakes, is short again, and books another -- twenty logins an hour, none of them
     * doing anything. A player who will afford the mine in a moment does not open the game for it, so the
     * wait has a floor. It is read by name from the behaviour data, and it delays nothing else: every
     * other reason to come back (a build, a research, a landing, an inbound) is its own earlier wake.
     */
    private function shortfallWakeFloorSeconds(): int
    {
        $minutes = $this->policy()['shortfall_wake']['floor_minutes'] ?? null;

        return is_numeric($minutes) ? max(0, (int) round((float) $minutes * 60.0)) : 0;
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        if (self::$policy !== null) {
            return self::$policy;
        }

        $path = dirname(__DIR__, 3) . self::PACING_POLICY;
        $parsed = is_file($path) ? Yaml::parseFile($path) : [];

        return self::$policy = is_array($parsed) ? $parsed : [];
    }

    /** The body the observation named, among the account's own. */
    private function ownedPlanet(PlayerService $player, int $planetId): ?PlanetService
    {
        foreach ($player->planets->all() as $planet) {
            if ($planet->getPlanetId() === $planetId) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * The seconds the account arrives after a material event: right-skewed (squared), so it
     * mostly checks shortly after the event and occasionally much later (SP3 jitter).
     */
    private function materialArrivalDelay(int $seed, string $eventKey): int
    {
        $unit = $this->randomSource->unitInterval($seed, 'sp3:arrival:' . $eventKey);

        return self::MATERIAL_EVENT_ARRIVAL_MIN_SECONDS
            + (int) round((self::MATERIAL_EVENT_ARRIVAL_MAX_SECONDS - self::MATERIAL_EVENT_ARRIVAL_MIN_SECONDS) * $unit * $unit);
    }

    /**
     * Caps the next session at one day before the host would delete an inactive
     * account, read from the host's own setting. Zero (the default) disables the
     * clamp, so the persona's holiday cadence is unchanged unless the operator
     * has actually enabled inactive-player deletion.
     */
    private function livenessFloor(CarbonImmutable $now, CarbonImmutable $nextDueAt): CarbonImmutable
    {
        $deletionDays = app(SettingsService::class)->inactivePlayerDeletionDays();
        if ($deletionDays <= 1) {
            return $nextDueAt;
        }

        $floor = $now->addDays($deletionDays - 1);

        return $nextDueAt->lessThan($floor) ? $nextDueAt : $floor;
    }

    private function recordDecisionTrace(AiProfile $profile, AiWorkItem $workItem, DecisionTrace $trace, CarbonImmutable $now): void
    {
        AiDecisionTrace::query()->create([
            'player_id' => $profile->player_id,
            'work_item_id' => $workItem->id,
            ...$trace->record(),
            'expires_at' => $now->addDays(self::TRACE_RETENTION_DAYS),
        ]);
    }

    private function scheduleSuccessor(AiProfile $profile, RoutineProfile $routine, AiSchedule $schedule, CarbonImmutable $sessionEndsAt, CarbonImmutable $nextDueAt, int $nextGeneration, CarbonImmutable $now): void
    {
        $schedule->update([
            'timezone' => $routine->timezone,
            'next_due_at' => $nextDueAt,
            'session_ends_at' => $sessionEndsAt,
            'generation' => $nextGeneration,
            'last_activity_at' => $now,
        ]);
        // Generation is part of the idempotency key: retries of this session
        // converge on one successor, while a later session gets new work.
        AiWorkItem::query()->firstOrCreate(
            ['idempotency_key' => 'session:' . $profile->player_id . ':' . $nextGeneration],
            [
                'player_id' => $profile->player_id,
                'kind' => AiWorkKind::RunSession,
                'due_at' => $nextDueAt,
                'schedule_generation' => $nextGeneration,
                'state' => AiWorkState::Pending,
            ],
        );
    }
}
