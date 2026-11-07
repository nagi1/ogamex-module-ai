<?php

namespace Modules\AI\Domain\Advisor;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per advisor consultation, for the operator: the rule's choice, the advice beside it, whether
 * it was applied, the status of the call and its usage. The prompt and the written text are not kept.
 *
 * @property int $player_id
 * @property string $area
 * @property string $moment
 * @property string $mode
 * @property string $rule_choice
 * @property string|null $advice
 * @property bool $applied
 * @property string $status
 * @property string|null $reason
 * @property list<int> $evidence_ids
 * @property string|null $provider
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float|null $cost
 * @property int|null $usage_reservation_id
 */
#[Unguarded]
#[Table(name: 'ai_advisor_receipts')]
class AdvisorReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'evidence_ids' => 'array',
            'applied' => 'boolean',
            'cost' => 'float',
        ];
    }
}
