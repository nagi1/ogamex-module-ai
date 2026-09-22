<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One staff-run operation, appended like the switch. The newest rows are the history an operator
 * reads after an incident: who queued what, and whether it finished.
 *
 * @property string $operation
 * @property int|null $actor_player_id
 * @property string $status
 * @property string|null $result
 * @property Carbon|null $finished_at
 */
#[Unguarded]
class AiOperationLog extends Model
{
    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }
}
