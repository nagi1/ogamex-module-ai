<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiLanguageInterpretation;
use Modules\AI\Enums\AiLanguageRequestState;

/**
 * @property int $conversation_reply_id
 * @property AiLanguageRequestState $state
 * @property int $usage_reservation_id
 */
#[Unguarded]
class AiLanguageRequest extends Model
{
    protected function casts(): array
    {
        return [
            'state' => AiLanguageRequestState::class,
            'interpretation' => AiLanguageInterpretation::class,
        ];
    }
}
