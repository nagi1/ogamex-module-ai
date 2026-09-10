<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiReceiptState;

/**
 * @property int $id
 * @property AiReceiptState $state
 */
class AiActionReceipt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['action_type' => AiActionType::class, 'state' => AiReceiptState::class, 'result' => 'array'];
    }
}
