<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiUsageReservationState;

/**
 * A reservation remains the authoritative duplicate guard until actual usage is settled once.
 *
 * @property string $universe_scope
 * @property int $player_id
 * @property string $conversation_key
 * @property string $reserved_for
 * @property int $reserved_input_tokens
 * @property int $reserved_output_tokens
 * @property int|null $actual_input_tokens
 * @property int|null $actual_output_tokens
 * @property AiUsageReservationState $state
 */
#[Unguarded]
class AiUsageReservation extends Model
{
    protected function casts(): array
    {
        return [
            'reserved_for' => 'immutable_date',
            'state' => AiUsageReservationState::class,
            'settled_at' => 'datetime',
        ];
    }
}
