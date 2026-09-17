<?php

namespace Modules\AI\Domain\Experience;

use Illuminate\Support\Collection;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Models\AiExperienceCase;

class NativeExperienceEngine implements ExperienceEngine
{
    /**
     * The eligible casebase, keyed by (owner, family, versions).
     *
     * A decision scores every candidate against the same casebase, so without this
     * the fetch ran once per candidate. The engine is resolved per perception and
     * the decision is read-only, so the cached rows cannot be stale within its life.
     *
     * @var array<string, Collection<int, AiExperienceCase>>
     */
    private array $casebaseCache = [];

    public function rankSimilarExperiences(ExperienceQuery $query): array
    {
        return array_values($this->casebase($query)
            ->map(fn (AiExperienceCase $case): RankedExperience => app()->makeWith(RankedExperience::class, [
                'caseId' => $case->id,
                'outcome' => $case->outcome,
                'similarity' => $this->similarity($query->features, $case->features),
                'utility' => (float) $case->utility,
                'uncertainty' => (float) $case->uncertainty,
            ]))
            ->sortByDesc(fn (RankedExperience $experience): array => [$experience->similarity, -$experience->caseId])
            ->take(max(0, $query->limit))
            ->values()
            ->all());
    }

    /** @return Collection<int, AiExperienceCase> */
    private function casebase(ExperienceQuery $query): Collection
    {
        $key = $query->playerId . ':' . $query->family->value . ':' . $query->featureVersion . ':' . $query->rulesetVersion;

        return $this->casebaseCache[$key] ??= AiExperienceCase::query()
            ->where('player_id', $query->playerId)
            ->where('family', $query->family)
            ->where('feature_version', $query->featureVersion)
            ->where('ruleset_version', $query->rulesetVersion)
            ->whereIn('outcome', [AiExperienceOutcome::Succeeded, AiExperienceOutcome::Failed, AiExperienceOutcome::Inconclusive])
            ->get();
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
