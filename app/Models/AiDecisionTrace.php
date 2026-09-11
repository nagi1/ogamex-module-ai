<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiCandidateActionType;

/**
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
