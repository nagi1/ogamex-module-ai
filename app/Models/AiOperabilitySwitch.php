<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One staff decision about whether the population may start new work.
 *
 * Rows are appended, never updated: the current switch is the newest row and the history is
 * what an operator can show after an incident.
 *
 * @property bool $enabled
 * @property string $reason
 * @property int|null $actor_player_id
 * @property Carbon $changed_at
 */
#[Unguarded]
class AiOperabilitySwitch extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'changed_at' => 'datetime'];
    }
}
