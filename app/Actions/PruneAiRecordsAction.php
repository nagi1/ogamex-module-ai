<?php

namespace Modules\AI\Actions;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiScoreSample;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;

/**
 * Enforces the retention the module declares, instead of only writing it down.
 *
 * Several tables record an `expires_at` for a single row's *validity* -- a trace
 * is no longer evidence after thirty days, a sealed reply is no longer worth
 * sending after three hours -- and a validity filter keeps such a row out of
 * every query while leaving it on disk forever. A module that runs one session
 * per account per hour against an unbounded population cannot leave that to the
 * disk, so the rows that have outlived their purpose are deleted here.
 *
 * What is deleted is bookkeeping, never the account's memory: relationships,
 * commitments, memory facts, emotional episodes, social exchanges and
 * experience cases all stay, because they are what the account knows rather
 * than what it happened to log. The windows are the ones the plan declares,
 * sized from the pilot's measured volumes against the 2 vCPU / 2 GB profile.
 */
class PruneAiRecordsAction
{
    /** @var array<class-string<Model>, int> */
    private const RETENTION_DAYS = [
        AiWorkItem::class => 90,
        AiActionReceipt::class => 90,
        AiDecisionTrace::class => 30,
        AiObservation::class => 30,
        AiConversationReply::class => 30,
        // The score series is not a log: it is the only record of what the accounts' points were,
        // because the host keeps current points and no history, so a review cannot re-derive a
        // window it has deleted. It is kept long enough to compare a window against the same
        // cohort months earlier, and it stays one row per account per hour either way.
        AiScoreSample::class => 400,
    ];

    public function __construct(private AiClock $clock)
    {
    }

    public function handle(): int
    {
        $now = $this->clock->now();
        $pruned = 0;

        foreach (self::RETENTION_DAYS as $model => $days) {
            $pruned += $model::query()->where('created_at', '<', $now->subDays($days))->delete();
        }

        return $pruned;
    }
}
