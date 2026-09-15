<?php

namespace Modules\AI\Infrastructure\Experience;

use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Domain\Experience\RankedExperience;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Models\AiExperienceCase;

/**
 * Ranks experience cases with the CBRKit driver.
 *
 * The module owns the cases, the owner/version scoping and the deterministic
 * tie-break; the driver only scores the casebase it is handed. That keeps a driver
 * swap from changing which evidence exists, and makes the substitution an
 * implementation change rather than a change of metric.
 */
class CbrKitExperienceEngine implements ExperienceEngine
{
    public function __construct(private readonly ExperienceEngine $fallback, private readonly CbrKitClient $client)
    {
    }

    public function rankSimilarExperiences(ExperienceQuery $query): array
    {
        $cases = $this->candidates($query);

        if ($cases === []) {
            return [];
        }

        $similarities = $this->client->rank($this->casebase($cases), $query->features);

        if ($similarities === null) {
            return $this->fallback->rankSimilarExperiences($query);
        }

        return $this->rank($query, $cases, $similarities);
    }

    /**
     * Only finalized, owner-scoped, version-compatible cases are eligible; a pending
     * decision is not evidence and a foreign case must never leak into a ranking.
     *
     * @return array<int, AiExperienceCase>
     */
    private function candidates(ExperienceQuery $query): array
    {
        return AiExperienceCase::query()
            ->where('player_id', $query->playerId)
            ->where('family', $query->family)
            ->where('feature_version', $query->featureVersion)
            ->where('ruleset_version', $query->rulesetVersion)
            ->whereIn('outcome', [AiExperienceOutcome::Succeeded, AiExperienceOutcome::Failed, AiExperienceOutcome::Inconclusive])
            ->orderBy('id')
            ->limit(max(0, (int) config('ai.cognition.experience.cbrkit.maximum_cases', 200)))
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<int, AiExperienceCase>  $cases
     * @return array<int, array<string, int|float|string|null>>
     */
    private function casebase(array $cases): array
    {
        $casebase = [];

        foreach ($cases as $id => $case) {
            $casebase[$id] = $case->features;
        }

        return $casebase;
    }

    /**
     * @param  array<int, AiExperienceCase>  $cases
     * @param  array<int, float>  $similarities
     * @return list<RankedExperience>
     */
    private function rank(ExperienceQuery $query, array $cases, array $similarities): array
    {
        $scored = [];

        foreach ($cases as $id => $case) {
            $scored[] = ['case' => $case, 'similarity' => $similarities[$id]];
        }

        // The driver's own tie order is unspecified, so the module re-applies its
        // deterministic ordering: highest similarity first, then lowest case id.
        usort($scored, static function (array $left, array $right): int {
            $bySimilarity = $right['similarity'] <=> $left['similarity'];

            if ($bySimilarity !== 0) {
                return $bySimilarity;
            }

            return $left['case']->id <=> $right['case']->id;
        });

        $ranked = [];

        foreach (array_slice($scored, 0, max(0, $query->limit)) as $entry) {
            $ranked[] = app()->makeWith(RankedExperience::class, [
                'caseId' => $entry['case']->id,
                'outcome' => $entry['case']->outcome,
                'similarity' => $entry['similarity'],
                'utility' => (float) $entry['case']->utility,
                'uncertainty' => (float) $entry['case']->uncertainty,
                'driverSimilarity' => $entry['similarity'],
            ]);
        }

        return $ranked;
    }
}
