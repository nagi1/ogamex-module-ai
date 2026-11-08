<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Models\AiExperienceCase;

class RecordAiExperienceOutcomeAction
{
    /** @param array<string, int|float|string|null> $features */
    public function handle(int $playerId, int $outcomeObservationId, AiExperienceCaseFamily $family, AiExperienceOutcome $outcome, string $featureVersion, string $rulesetVersion, array $features, float $utility, float $uncertainty): AiExperienceCase|null
    {
        if ($outcome === AiExperienceOutcome::Pending) {
            return null;
        }

        // Two processes can see the same completion at once (the scheduler and a page load): insert first and read the
        // winner's row on the unique key, so the loser does not throw into the host's queue processing.
        return AiExperienceCase::query()->createOrFirst([
            'player_id' => $playerId,
            'outcome_observation_id' => $outcomeObservationId,
            'family' => $family,
        ], [
            'outcome' => $outcome,
            'feature_version' => $featureVersion,
            'ruleset_version' => $rulesetVersion,
            'features' => $features,
            'utility' => max(-1, min(1, $utility)),
            'uncertainty' => max(0, min(1, $uncertainty)),
        ]);
    }
}
