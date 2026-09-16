<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Models\AiCampaign;

class OpenAiCampaignAction
{
    public function handle(CarbonImmutable $startsAt, CarbonImmutable $endsAt): AiCampaign
    {
        if ($endsAt->lte($startsAt)) {
            throw new InvalidArgumentException('A campaign must end after it starts.');
        }

        return AiCampaign::query()->create([
            'state' => AiCampaignState::Preparing,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
