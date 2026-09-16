<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiCampaignState;

/**
 * One cooperative PvE campaign: a published window against the disclosed AI faction.
 *
 * @property int $id
 * @property AiCampaignState $state
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Unguarded]
class AiCampaign extends Model
{
    protected function casts(): array
    {
        return ['state' => AiCampaignState::class, 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /**
     * @return HasMany<AiCampaignObjective, $this>
     */
    public function objectives(): HasMany
    {
        return $this->hasMany(AiCampaignObjective::class, 'campaign_id');
    }
}
