<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A declared AI stronghold for one campaign: an ordinary host planet, never a module object.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $planet_id
 * @property Carbon|null $completed_at
 */
#[Unguarded]
class AiCampaignObjective extends Model
{
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
