<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;

/**
 * An immutable pointer to a host fact the AI was legally allowed to observe.
 *
 * Raw chat stays in OGameX. Later cognition retrieves it only through its source
 * reference, after applying the visibility rules for the current operation.
 *
 * @property int $player_id
 * @property AiObservationSource $source_type
 * @property int $source_id
 * @property AiObservationKind $kind
 * @property int|null $subject_player_id
 * @property Carbon $source_time
 * @property Carbon $observed_at
 */
#[Unguarded]
class AiObservation extends Model
{
    protected function casts(): array
    {
        return [
            'source_type' => AiObservationSource::class,
            'kind' => AiObservationKind::class,
            'source_time' => 'datetime',
            'observed_at' => 'datetime',
        ];
    }
}
