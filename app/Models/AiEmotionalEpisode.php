<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiAffectEmotion;

#[Unguarded]
class AiEmotionalEpisode extends Model
{
    protected function casts(): array
    {
        return ['emotion' => AiAffectEmotion::class, 'intensity' => 'decimal:4', 'occurred_at' => 'datetime'];
    }
}
