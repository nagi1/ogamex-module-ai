<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/** Sparse social state: rows exist only after a legally observed interaction. */
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
