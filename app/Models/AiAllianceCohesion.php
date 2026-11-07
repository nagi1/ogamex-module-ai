<?php

namespace Modules\AI\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * One alliance's asabiyya (diplomacy.rs arm `cycles`): it wears down in peace, war renews it, and under the split line the
 * club may break apart or be overrun by a nomad band.
 *
 * @property int $alliance_id
 * @property float $asabiyya
 * @property CarbonInterface|null $risen_against_at
 */
#[Unguarded]
class AiAllianceCohesion extends Model
{
    protected function casts(): array
    {
        return ['asabiyya' => 'float', 'risen_against_at' => 'datetime'];
    }
}
