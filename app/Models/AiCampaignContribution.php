<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiCampaignContributionKind;

/**
 * A verified contribution to one campaign, credited once per source operation.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $player_id
 * @property AiCampaignContributionKind $kind
 * @property string $source_type
 * @property int $source_id
 */
#[Unguarded]
class AiCampaignContribution extends Model
{
    protected function casts(): array
    {
        return ['kind' => AiCampaignContributionKind::class];
    }
}
