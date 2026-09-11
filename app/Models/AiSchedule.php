<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $player_id
 * @property int $generation
 * @property Carbon $next_due_at
 */
#[Unguarded]
class AiSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'next_due_at' => 'datetime',
            'session_ends_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }
}
