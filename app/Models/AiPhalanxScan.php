<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sensor-phalanx scan of a raid target, folded into the raid decision.
 *
 * The scan itself is a host read; the module keeps only the one number the raid
 * decision consumes — how many ships were arriving at the target when it was
 * scanned. Raw fleet data stays in the host's own mission rows.
 *
 * @property int $player_id
 * @property int $moon_planet_id
 * @property int $target_planet_id
 * @property int $incoming_ship_count
 * @property Carbon $observed_at
 */
#[Unguarded]
class AiPhalanxScan extends Model
{
    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
        ];
    }
}
