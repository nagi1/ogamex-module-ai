<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiReceiptState;

/**
 * @property int $id
 * @property AiReceiptState $state
 * @property array<string, mixed> $result
 */
#[Unguarded]
class AiActionReceipt extends Model
{
    protected function casts(): array
    {
        return ['action_type' => AiActionType::class, 'state' => AiReceiptState::class, 'result' => 'array'];
    }
}
