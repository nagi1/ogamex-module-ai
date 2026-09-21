<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiReceiptState;

/**
 * @property int $id
 * @property int $player_id
 * @property string $idempotency_key
 * @property AiActionType $action_type
 * @property AiReceiptState $state
 * @property array<string, mixed> $result
 * @property Carbon $created_at
 */
#[Unguarded]
class AiActionReceipt extends Model
{
    protected function casts(): array
    {
        return ['action_type' => AiActionType::class, 'state' => AiReceiptState::class, 'result' => 'array'];
    }
}
