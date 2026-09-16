<?php

namespace Modules\AI\Domain\Scheduling;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Decision\DecisionTrace;
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
use OGame\Models\BuildingQueue;
use OGame\Models\FleetMission;
use OGame\Models\ResearchQueue;

/**
 * Records a deterministic session decision and schedules exactly one future
 * session. Action execution is deliberately outside this service: unavailable
 * capabilities remain traceable intents rather than requiring AI-specific host
 * contracts.
 */
class SessionDecisionService
{
    private const TRACE_RETENTION_DAYS = 30;

    /** SP3: the account arrives this many seconds after a material event, right-skewed toward short. */
    private const MATERIAL_EVENT_ARRIVAL_MIN_SECONDS = 30;

    private const MATERIAL_EVENT_ARRIVAL_MAX_SECONDS = 300;

    public function __construct(
        private PlayerPerceptionBuilder $playerPerceptionBuilder,
        private SessionPlanner $sessionPlanner,
        private NextDueTimeCalculator $nextDueTimeCalculator,
        private DecisionEngine $decisionEngine,
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
        $upcomingAbsenceMinutes = (int) $plan->nextDueAt->diffInMinutes($plan->sessionEndsAt);

        $perception = $this->playerPerceptionBuilder->build($profile->player_id, $upcomingAbsenceMinutes);
        $decisionKey = 'work:' . $workItem->id . ':generation:' . $schedule->generation;
        $trace = $this->decisionEngine->decide($profile, $perception, $decisionKey);

        $this->recordDecisionTrace($profile, $workItem, $trace, $now);

        // An account with no planets, or one the host no longer has, has nothing
        // to come back to. The session still records what it decided, and the
        // chain stops instead of deciding nothing forever -- and stopping it is
        // the whole difference between an idle account and a stated one.
        if (!$this->accountStateResolver->resolve($profile->player_id)->schedules()) {
            return $trace;
        }

        $nextDueAt = $this->nextDueTimeCalculator->fromSession($plan, $now);

        // V2: a hostile inbound schedules the reaction wake, so the account reacts inside the
        // window before impact instead of at its next ordinary session.
        if ($perception->reactionWakeAt !== null) {
            $reactionWakeAt = CarbonImmutable::createFromTimestamp($perception->reactionWakeAt);
            if ($reactionWakeAt->greaterThan($now) && $reactionWakeAt->lessThan($nextDueAt)) {
                $nextDueAt = $reactionWakeAt;
            }
        }

        // SP3: wake at the next material event inside the waking window (a build, research or
        // fleet landing) instead of the full routine gap; the routine session stays the bound.
        $nextDueAt = $this->nextMaterialEventWake($profile, $perception, $now, $nextDueAt);

        $nextGeneration = $schedule->generation + 1;

        $this->scheduleSuccessor($profile, $routine, $schedule, $plan->sessionEndsAt, $nextDueAt, $nextGeneration, $now);

        return $trace;
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

        return $etas;
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
