<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiUsageBudgetScope;

/**
 * Per-scope rows are locked in a stable order so overlapping reservations cannot exceed a cap.
 *
 * @property AiUsageBudgetScope $scope
 * @property string $scope_key
 * @property int $reserved_attempts
 * @property int $reserved_input_tokens
 * @property int $reserved_output_tokens
 */
#[Unguarded]
class AiUsageBudget extends Model
{
    protected function casts(): array
    {
        return [
            'scope' => AiUsageBudgetScope::class,
            'reserved_for' => 'immutable_date',
        ];
    }
}
