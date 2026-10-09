<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiCommitmentDirection;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiSocialExchangeType;

/**
 * Exact terms remain immutable after acceptance; fulfillment is a separate evidence-backed transition.
 *
 * @property int $player_id
 * @property int $counterparty_player_id
 * @property int $source_observation_id
 * @property int|null $fulfillment_observation_id
 * @property AiCommitmentState $state
 * @property AiCommitmentDirection $direction
 * @property array<string, mixed> $terms
 * @property int $revision
 * @property Carbon|null $due_at
 */
#[Unguarded]
class AiCommitment extends Model
{
    protected function casts(): array
    {
        return [
            'state' => AiCommitmentState::class,
            'direction' => AiCommitmentDirection::class,
            'terms' => 'array',
            'due_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /**
     * Agreed ceasefires still running between this player and any other, whichever side asked: a truce binds both.
     *
     * @param Builder<self> $query
     */
    public function scopeHeldCeasefire(Builder $query, int $playerId): void
    {
        $query->where('state', AiCommitmentState::Accepted)
            ->where('due_at', '>', now())
            ->whereIn('id', AiSocialExchange::query()->where('type', AiSocialExchangeType::CeasefireRequest)->whereNotNull('commitment_id')->select('commitment_id'))
            ->where(static fn (Builder $side) => $side->where('player_id', $playerId)->orWhere('counterparty_player_id', $playerId));
    }
}
