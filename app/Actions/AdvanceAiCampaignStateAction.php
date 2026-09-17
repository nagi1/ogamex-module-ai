<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignObjective;
use Modules\AI\Support\AiClock;

/**
 * Advances campaigns through their published lifecycle.
 *
 * Preparing becomes Active once the announced window opens. An Active campaign is Resolved the
 * moment every declared stronghold has been completed on time, and Failed once the deadline
 * passes with a stronghold still standing or none ever announced. Both terminal states are
 * final, so a later pass never rewrites a recorded outcome.
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

        if ($this->completedOnTime($campaign)) {
            $campaign->state = AiCampaignState::Resolved;
            $campaign->save();

            return;
        }

        if ($campaign->ends_at !== null && $now->gt($campaign->ends_at)) {
            $campaign->state = AiCampaignState::Failed;
            $campaign->save();
        }
    }

    private function completedOnTime(AiCampaign $campaign): bool
    {
        $objectives = $campaign->objectives()->get();

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
