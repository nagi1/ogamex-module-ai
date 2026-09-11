<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;

/**
 * A scoped, attributed assertion. Claims remain claims until a permitted source verifies them.
 *
 * @property int $player_id
 * @property int $subject_player_id
 * @property AiMemoryPredicate $predicate
 * @property AiMemoryEvidenceKind $evidence_kind
 * @property int|null $speaker_player_id
 * @property array<string, mixed> $value
 */
#[Unguarded]
class AiMemoryFact extends Model
{
    protected function casts(): array
    {
        return [
            'predicate' => AiMemoryPredicate::class,
            'evidence_kind' => AiMemoryEvidenceKind::class,
            'value' => 'array',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
