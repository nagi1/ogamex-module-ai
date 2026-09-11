<?php

namespace Modules\AI\Domain\Scheduling;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Decision\DecisionTrace;
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

/**
 * Records a deterministic session decision and schedules exactly one future
 * session. Action execution is deliberately outside this service: unavailable
 * capabilities remain traceable intents rather than requiring AI-specific host
 * contracts.
 */
class SessionDecisionService
{
    private const TRACE_RETENTION_DAYS = 30;

    public function __construct(
        private PlayerPerceptionBuilder $playerPerceptionBuilder,
        private SessionPlanner $sessionPlanner,
        private NextDueTimeCalculator $nextDueTimeCalculator,
        private DecisionEngine $decisionEngine,
        private AiClock $clock,
    ) {
    }

    public function run(AiProfile $profile, AiWorkItem $workItem): DecisionTrace
    {
        $now = $this->clock->now();
        $routine = RoutineProfile::fromAiProfile($profile);
        $schedule = $this->scheduleFor($profile, $routine, $now);
        $perception = $this->playerPerceptionBuilder->build($profile->player_id);
        $decisionKey = 'work:' . $workItem->id . ':generation:' . $schedule->generation;
        $trace = $this->decisionEngine->decide($profile, $perception, $decisionKey);
        $plan = $this->sessionPlanner->plan($profile, $now, $schedule->generation);
        $nextDueAt = $this->nextDueTimeCalculator->fromSession($plan, $now);
        $nextGeneration = $schedule->generation + 1;

        $this->recordDecisionTrace($profile, $workItem, $trace, $now);
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
