<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
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

        return $commitment->refresh();
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
