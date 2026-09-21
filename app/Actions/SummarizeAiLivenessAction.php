<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Operability\AiLivenessOverview;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;

/**
 * Distinguishes "the game is quiet" from "the scheduler is broken" with figures the schedule
 * and work tables already write: the newest activity, accounts now overdue, and the queued and
 * leased work counts. A healthy quiet universe shows old activity with nothing overdue; a
 * stalled worker shows overdue accounts and a stretching gap.
 */
class SummarizeAiLivenessAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(): AiLivenessOverview
    {
        $now = $this->clock->now();

        return app()->makeWith(AiLivenessOverview::class, [
            'lastActivityAt' => $this->lastActivity(),
            'overdueAccounts' => AiSchedule::query()->where('next_due_at', '<', $now)->count(),
            'dueWork' => AiWorkItem::query()
                ->whereIn('state', [AiWorkState::Pending, AiWorkState::Retry])
                ->where('due_at', '<=', $now)
                ->count(),
            'sessionsInFlight' => AiWorkItem::query()
                ->where('state', AiWorkState::Leased)
                ->where('lease_until', '>', $now)
                ->count(),
        ]);
    }

    private function lastActivity(): string|null
    {
        $latest = AiSchedule::query()->max('last_activity_at');

        return $latest === null ? null : CarbonImmutable::parse($latest)->toDateTimeString();
    }
}
