<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiAffectEmotion;

/**
 * @property int $revision
 * @property string $intensity
 * @property \Illuminate\Support\Carbon $updated_for
 */
#[Unguarded]
class AiAffectState extends Model
{
    protected function casts(): array
    {
        return [
            'emotion' => AiAffectEmotion::class,
            'intensity' => 'decimal:4',
            'updated_for' => 'datetime',
        ];
    }
}
