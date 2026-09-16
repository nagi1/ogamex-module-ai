<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use Modules\AI\Enums\AiCampaignConsultationTrigger;

/**
 * The one receipt per consultation attempt, written for the operator and never for the provider.
 *
 * It records what the lane did — trigger, configuration revision, cited evidence ids, provider
 * and model, settled usage, validation result and whether an adjustment became active for a later
 * ranking — but never the raw prompt, the private fact text or the generated reasoning, which are
 * exactly the fields the lane must not retain.
 *
 * @property int $player_id
 * @property int $campaign_id
 * @property AiCampaignConsultationTrigger $trigger
 * @property AiCampaignConsultationStatus $status
 * @property int|null $candidate_id
 * @property array<int, int> $evidence_ids
 * @property string $config_revision
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $provider_request_id
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int|null $usage_reservation_id
 * @property bool $changed_ranking
 * @property string $request_key
 */
#[Unguarded]
class AiCampaignConsultationReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'trigger' => AiCampaignConsultationTrigger::class,
            'status' => AiCampaignConsultationStatus::class,
            'evidence_ids' => 'array',
            'changed_ranking' => 'boolean',
        ];
    }
}
