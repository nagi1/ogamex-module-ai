<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;

/**
 * @property int $id
 * @property int $player_id
 * @property int $counterparty_player_id
 * @property int $source_observation_id
 * @property int $protocol_depth
 * @property AiSocialExchangeType $type
 * @property array<string, mixed> $terms
 * @property AiSocialExchangeState $state
 * @property AiSocialResponse|null $response
 * @property array<string, mixed>|null $response_terms
 * @property string|null $response_reason
 * @property Carbon|null $due_at
 * @property Carbon|null $responded_at
 * @property int|null $commitment_id
 * @property int $revision
 */
#[Unguarded]
class AiSocialExchange extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AiSocialExchangeType::class,
            'state' => AiSocialExchangeState::class,
            'response' => AiSocialResponse::class,
            'terms' => 'array',
            'response_terms' => 'array',
            'due_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }
}
