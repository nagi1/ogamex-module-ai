<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiCampaignContributionKind;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignContribution;
use Modules\AI\Models\AiProfile;

class RecordAiCampaignContributionAction
{
    public function handle(int $campaignId, int $playerId, AiCampaignContributionKind $kind, string $sourceType, int $sourceId): AiCampaignContribution|null
    {
        if (AiCampaign::query()->whereKey($campaignId)->doesntExist()) {
            return null;
        }

        // The contribution board is the human coalition's recognition, so an AI-faction
        // account is never credited for its own campaign work; the board distinguishes
        // verified support, not what the defender did.
        if ($this->isAiPlayer($playerId)) {
            return null;
        }

        return AiCampaignContribution::query()->firstOrCreate([
            'campaign_id' => $campaignId,
            'player_id' => $playerId,
            'kind' => $kind,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    private function isAiPlayer(int $playerId): bool
    {
        return AiProfile::query()
            ->where('player_id', $playerId)
            ->where('enabled', true)
            ->exists();
    }
}
