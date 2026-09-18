<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignObjective;
use Modules\AI\Support\AiClock;

/**
 * Advances campaigns through their published lifecycle.
 *
 * Preparing becomes Active once the announced window opens. An Active campaign is now a race up
 * the declared stronghold ladder: the coalition wins (Resolved) by completing the ladder on time,
 * and the faction wins (FactionWon) once its momentum counter tops the same ladder first. A
 * deadline with work still open fails it. Terminal states are final, so a later pass never
 * rewrites a recorded outcome.
 */
class AdvanceAiCampaignStateAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(): int
    {
        $now = $this->clock->now();
        $advanced = 0;

        $campaigns = AiCampaign::query()
            ->whereIn('state', [AiCampaignState::Preparing, AiCampaignState::Active])
            ->get();

        foreach ($campaigns as $campaign) {
            $before = $campaign->state;

            $this->settle($campaign, $now);

            if ($campaign->state !== $before) {
                $advanced++;
            }
        }

        return $advanced;
    }

    private function settle(AiCampaign $campaign, CarbonImmutable $now): void
    {
        if ($campaign->state === AiCampaignState::Preparing && $campaign->starts_at !== null && $now->gte($campaign->starts_at)) {
            $campaign->state = AiCampaignState::Active;
            $campaign->save();

            app(RecordCampaignConsultationSignalAction::class)->handle($campaign->id, AiCampaignConsultationTrigger::NewPhase, $now);
        }

        if ($campaign->state !== AiCampaignState::Active) {
            return;
        }

        $objectives = $campaign->objectives()->get();

        if ($this->completedOnTime($campaign, $objectives)) {
            $campaign->state = AiCampaignState::Resolved;
            $campaign->save();

            return;
        }

        // The faction climbs the same ladder across the published window, so the
        // momentum bar is the elapsed fraction of the window and can never outrun
        // the schedule (IMPL-050): a seven-day campaign with three strongholds is
        // still near the foot of the ladder after an hour, not three passes.
        $campaign->faction_momentum = $this->momentum($campaign, $objectives->count(), $now);
        $campaign->save();

        if ($campaign->ends_at !== null && $now->gte($campaign->ends_at)) {
            // The deadline is the faction's finish line: a ladder still standing is a
            // faction win, an empty ladder has nothing to lose and just lapses.
            $campaign->state = $objectives->isEmpty() ? AiCampaignState::Failed : AiCampaignState::FactionWon;
            $campaign->save();
        }
    }

    /**
     * The faction's rung on the ladder, derived from how much of the window has
     * elapsed — never from how many passes ran. Clamped to the ladder length.
     */
    private function momentum(AiCampaign $campaign, int $objectiveCount, CarbonImmutable $now): int
    {
        if ($objectiveCount === 0 || $campaign->starts_at === null || $campaign->ends_at === null) {
            return 0;
        }

        $window = $campaign->ends_at->getTimestamp() - $campaign->starts_at->getTimestamp();
        if ($window <= 0) {
            return $objectiveCount;
        }

        $elapsed = max(0, $now->getTimestamp() - $campaign->starts_at->getTimestamp());

        return min($objectiveCount, (int) floor($elapsed / $window * $objectiveCount));
    }

    private function completedOnTime(AiCampaign $campaign, Collection $objectives): bool
    {
        if ($objectives->isEmpty()) {
            return false;
        }

        if ($objectives->contains(fn (AiCampaignObjective $objective): bool => $objective->completed_at === null)) {
            return false;
        }

        // A completion is on time when the last stronghold fell before the deadline; the
        // campaign opens in a separate transition, so an earlier fall is read by that same
        // deadline rather than a second, pre-window guard.
        return $objectives->max('completed_at')->lte($campaign->ends_at);
    }
}
