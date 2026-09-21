<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiCommitmentDirection;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Models\AiCommitment;

class FulfillAiCommitmentAction
{
    public function handle(int $commitmentId, int $fulfillmentObservationId, CarbonImmutable $fulfilledAt): AiCommitment|null
    {
        $commitment = AiCommitment::query()->find($commitmentId);

        if ($commitment === null) {
            return null;
        }

        if ($commitment->state !== AiCommitmentState::Accepted) {
            return $commitment;
        }

        if ($commitment->due_at?->lessThan($fulfilledAt)) {
            return $this->expire($commitment, $fulfilledAt);
        }

        $commitment->update([
            'state' => AiCommitmentState::Fulfilled,
            'fulfillment_observation_id' => $fulfillmentObservationId,
            'fulfilled_at' => $fulfilledAt,
            'revision' => $commitment->revision + 1,
        ]);

        $this->recordRepair($commitment, $fulfilledAt);

        return $commitment->refresh();
    }

    /**
     * A counterparty promise that is actually kept is the only thing that earns trust back —
     * the genuine-amends half of "foes become friends". A promise the AI itself kept says
     * nothing about the counterparty, so only a fulfilled ExpectedFromCounterparty commitment
     * repairs the relationship. Expiry never repairs anything.
     */
    private function recordRepair(AiCommitment $commitment, CarbonImmutable $fulfilledAt): void
    {
        if ($commitment->direction !== AiCommitmentDirection::ExpectedFromCounterparty) {
            return;
        }

        app(RecordAiRelationshipInteractionAction::class)->handle(
            $commitment->player_id,
            $commitment->counterparty_player_id,
            $commitment->source_observation_id,
            $fulfilledAt,
            trustChange: 0.20,
            threatChange: -0.15,
            affinityChange: 0.10,
        );
    }

    private function expire(AiCommitment $commitment, CarbonImmutable $expiredAt): AiCommitment
    {
        $commitment->update([
            'state' => AiCommitmentState::Expired,
            'expired_at' => $expiredAt,
            'revision' => $commitment->revision + 1,
        ]);

        return $commitment->refresh();
    }
}
