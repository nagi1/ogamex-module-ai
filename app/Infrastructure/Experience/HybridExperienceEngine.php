<?php

namespace Modules\AI\Infrastructure\Experience;

use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Domain\Experience\RankedExperience;

/**
 * The hybrid experience engine: native supplies the floor and its own similarity, and the
 * CBRKit driver's own measure decides the order.
 *
 * The driver may only reorder evidence the module owns. Its score is carried on
 * `driverSimilarity` so a review can tell a driver order from a native one, while the native
 * similarity stays the canonical figure the decision policy already weighs. When the driver
 * degrades it returns the native list without a driver score, so the hybrid result is exactly
 * the native answer.
 */
class HybridExperienceEngine implements ExperienceEngine
{
    public function __construct(
        private readonly ExperienceEngine $native,
        private readonly ExperienceEngine $driver,
    ) {
    }

    public function rankSimilarExperiences(ExperienceQuery $query): array
    {
        $native = $this->native->rankSimilarExperiences($query);
        $driver = $this->driver->rankSimilarExperiences($query);

        $nativeByCase = [];

        foreach ($native as $case) {
            $nativeByCase[$case->caseId] = $case;
        }

        $merged = [];
        $seen = [];

        foreach ($driver as $case) {
            $nativeCase = $nativeByCase[$case->caseId] ?? null;

            // The driver may only reorder the native set, so a case native did not rank is
            // not evidence about this choice.
            if ($nativeCase === null) {
                continue;
            }

            $seen[$case->caseId] = true;

            $merged[] = app()->makeWith(RankedExperience::class, [
                'caseId' => $case->caseId,
                'outcome' => $case->outcome,
                'similarity' => $nativeCase->similarity,
                'utility' => $case->utility,
                'uncertainty' => $case->uncertainty,
                'driverSimilarity' => $case->driverSimilarity,
            ]);
        }

        // A native case the driver did not rank stays in the answer in native order: the
        // native set is authoritative and the driver only reorders what it was given.
        foreach ($native as $case) {
            if (!isset($seen[$case->caseId])) {
                $merged[] = $case;
            }
        }

        return $merged;
    }
}
