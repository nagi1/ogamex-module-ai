<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiCommitmentState;

/**
 * Exact terms remain immutable after acceptance; fulfillment is a separate evidence-backed transition.
 *
 * @property AiCommitmentState $state
 * @property int $revision
 * @property Carbon|null $due_at
 */
#[Unguarded]
class AiCommitment extends Model
{
    protected function casts(): array
    {
        return [
            'state' => AiCommitmentState::class,
            'terms' => 'array',
            'due_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }
}
