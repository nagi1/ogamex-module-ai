<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiCandidateActionType;

/**
 * @property int $player_id
 * @property Carbon $observed_at
 * @property string $selected_reason
 * @property AiCandidateActionType $selected_action
 * @property array<int, array<string, mixed>> $candidates
 * @property array<string, mixed> $score_components
 * @property array<string, string> $source_timestamps
 */
#[Unguarded]
class AiDecisionTrace extends Model
{
    protected function casts(): array
    {
        return [
            'selected_action' => AiCandidateActionType::class,
            'candidates' => 'array',
            'score_components' => 'array',
            'source_timestamps' => 'array',
            'observed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
