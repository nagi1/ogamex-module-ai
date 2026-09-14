<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiStopReason;

/**
 * One reason the module declined to do work, counted per day and scope.
 *
 * @property AiStopReason $reason
 * @property string $scope
 * @property Carbon $observed_on
 * @property int $occurrences
 * @property array<string, mixed>|null $last_context
 */
#[Unguarded]
class AiStopCounter extends Model
{
    protected function casts(): array
    {
        return [
            'reason' => AiStopReason::class,
            'observed_on' => 'date',
            'last_context' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
