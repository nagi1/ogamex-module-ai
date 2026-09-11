<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Models\AiCommitment;

class AcceptAiCommitmentAction
{
    public function handle(int $commitmentId): AiCommitment|null
    {
        $commitment = AiCommitment::query()->find($commitmentId);

        if ($commitment === null) {
            return null;
        }

        if ($commitment->state !== AiCommitmentState::Proposed) {
            return $commitment;
        }

        $commitment->update([
            'state' => AiCommitmentState::Accepted,
            'revision' => $commitment->revision + 1,
        ]);

        return $commitment->refresh();
    }
}
