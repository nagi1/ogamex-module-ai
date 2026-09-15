<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One account's public score as the host held it in one hour.
 *
 * The host keeps current points only, so the growth curve the authenticity signals are read from
 * cannot be recovered from the host schema: it exists because this module sampled it. A sample is
 * a copy of the host's numbers, never a module calculation of them.
 *
 * @property int $player_id
 * @property Carbon $sampled_at
 * @property int $general
 * @property int $economy
 * @property int $research
 * @property int $military_built
 * @property int $military_destroyed
 * @property int $military_lost
 * @property int|null $general_rank
 */
#[Unguarded]
class AiScoreSample extends Model
{
    protected function casts(): array
    {
        return ['sampled_at' => 'datetime'];
    }
}
