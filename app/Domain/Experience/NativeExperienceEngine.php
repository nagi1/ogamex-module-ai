<?php

namespace Modules\AI\Domain\Experience;

use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Models\AiExperienceCase;

class NativeExperienceEngine implements ExperienceEngine
{
    public function rankSimilarExperiences(ExperienceQuery $query): array
    {
        return array_values(AiExperienceCase::query()
            ->where('player_id', $query->playerId)
            ->where('family', $query->family)
            ->where('feature_version', $query->featureVersion)
            ->where('ruleset_version', $query->rulesetVersion)
            ->whereIn('outcome', [AiExperienceOutcome::Succeeded, AiExperienceOutcome::Failed, AiExperienceOutcome::Inconclusive])
            ->get()
            ->map(fn (AiExperienceCase $case): RankedExperience => new RankedExperience($case->id, $case->outcome, $this->similarity($query->features, $case->features), (float) $case->utility, (float) $case->uncertainty))
            ->sortByDesc(fn (RankedExperience $experience): array => [$experience->similarity, -$experience->caseId])
            ->take(max(0, $query->limit))
            ->values()
            ->all());
    }

    /**
     * @param array<string, int|float|string|null> $left
     * @param array<string, int|float|string|null> $right
     */
    private function similarity(array $left, array $right): float
    {
        $shared = array_intersect_key($left, $right);
        $known = array_filter($shared, fn (int|float|string|null $value): bool => $value !== null);

        if ($known === []) {
            return 0;
        }

        $score = array_sum(array_map(fn (string $key, int|float|string $value): float => $this->featureSimilarity($value, $right[$key]), array_keys($known), $known));

        return $score / count($known);
    }

    private function featureSimilarity(int|float|string $left, int|float|string|null $right): float
    {
        if ($right === null) {
            return 0;
        }

        if (is_string($left) || is_string($right)) {
            return $left === $right ? 1 : 0;
        }

        return max(0, 1 - abs((float) $left - (float) $right) / max(1, abs((float) $left), abs((float) $right)));
    }
}
