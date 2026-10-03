<?php

namespace Modules\AI\Infrastructure\Experience;

use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Contracts\PrefetchesExperience;
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
class CbrKitExperienceEngine implements ExperienceEngine, PrefetchesExperience
{
    /**
     * The eligible casebase, keyed by (owner, family, versions).
     *
     * A decision scores every candidate against the same casebase, so without
     * this the fetch ran once per candidate (a building sweep reached it ~27×
     * per perception). The engine is resolved per perception and the decision
     * is read-only, so the cached rows cannot be stale within its life.
     *
     * @var array<string, array<int, AiExperienceCase>>
     */
    private array $casebaseCache = [];

    /**
     * What the driver scored for one (casebase, query features) pair.
     *
     * The same object is asked about once per planet that could build it, and the casebase and the
     * driver are both fixed for the life of this engine, so the repeat asks are answered from here
     * instead of costing another HTTP round trip that carries the whole casebase. A failed call is
     * never kept (the null is not stored), so the circuit breaker still sees every retry.
     *
     * @var array<string, array<int, float>>
     */
    private array $similarityCache = [];

    public function __construct(private readonly ExperienceEngine $fallback, private readonly CbrKitClient $client)
    {
    }

    /**
     * One request for every query a caller is about to ask, sharing the casebase they all score against.
     * A query already answered is skipped, and a failed batch stores nothing, so each query is then asked
     * on its own exactly as before.
     */
    public function prefetch(array $queries): void
    {
        $groups = [];

        foreach ($queries as $query) {
            $cases = $this->candidates($query);

            if ($cases === []) {
                continue;
            }

            $casebase = $this->casebase($cases);
            $caseKey = md5(json_encode($casebase) ?: '');
            $key = md5(json_encode([$casebase, $query->features]) ?: '');

            if (!isset($this->similarityCache[$key])) {
                $groups[$caseKey]['casebase'] = $casebase;
                $groups[$caseKey]['queries'][$key] = $query->features;
            }
        }

        foreach ($groups as $group) {
            $answers = $this->client->rankMany($group['casebase'], $group['queries']);

            foreach ($answers ?? [] as $key => $similarities) {
                $this->similarityCache[$key] = $similarities;
            }
        }
    }

    public function rankSimilarExperiences(ExperienceQuery $query): array
    {
        $cases = $this->candidates($query);

        if ($cases === []) {
            return [];
        }

        $casebase = $this->casebase($cases);
        $key = md5(json_encode([$casebase, $query->features]) ?: '');
        $similarities = $this->similarityCache[$key] ??= $this->client->rank($casebase, $query->features);

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
        $key = $query->playerId . ':' . $query->family->value . ':' . $query->featureVersion . ':' . $query->rulesetVersion;

        return $this->casebaseCache[$key] ??= AiExperienceCase::query()
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
