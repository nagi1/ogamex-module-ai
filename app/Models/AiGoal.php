<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One intention the account is working towards across logins (architecture step 4, `GoalBoard`).
 *
 * The row is the account's own: it names what it wants and how far it has come, never an object or
 * an order, so a goal survives the login that took it up and is read by the next one.
 *
 * @property int $player_id
 * @property string $goal
 * @property int $target
 * @property int $progress
 * @property Carbon $started_at
 * @property Carbon $abandon_after
 */
#[Unguarded]
class AiGoal extends Model
{
    protected function casts(): array
    {
        return [
            'target' => 'integer',
            'progress' => 'integer',
            'started_at' => 'datetime',
            'abandon_after' => 'datetime',
        ];
    }
}
