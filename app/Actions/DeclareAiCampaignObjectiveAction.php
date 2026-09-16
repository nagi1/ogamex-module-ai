<?php

namespace Modules\AI\Actions;

use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignObjective;
use OGame\Models\Planet;

class DeclareAiCampaignObjectiveAction
{
    public function handle(int $campaignId, int $planetId): AiCampaignObjective|null
    {
        if (AiCampaign::query()->whereKey($campaignId)->doesntExist() || Planet::query()->whereKey($planetId)->doesntExist()) {
            return null;
        }

        return AiCampaignObjective::query()->firstOrCreate([
            'campaign_id' => $campaignId,
            'planet_id' => $planetId,
        ]);
    }
}
