<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Sparse social state: rows exist only after a legally observed interaction.
 *
 * @property string $trust
 * @property string $threat
 * @property string $affinity
 * @property string $respect
 * @property string $social_importance
 * @property int $revision
 * @property Carbon|null $last_interaction_at
 */
#[Unguarded]
class AiRelationship extends Model
{
    protected function casts(): array
    {
        return [
            'trust' => 'decimal:4',
            'threat' => 'decimal:4',
            'affinity' => 'decimal:4',
            'respect' => 'decimal:4',
            'social_importance' => 'decimal:4',
            'last_interaction_at' => 'datetime',
        ];
    }
}
