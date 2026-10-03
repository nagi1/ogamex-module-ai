<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Experience\ExperienceQuery;

/**
 * An experience engine that can answer several queries against one casebase in a single round trip.
 *
 * Calling it is optional and changes nothing a later `rankSimilarExperiences` returns: it only warms
 * what the engine would otherwise fetch one query at a time.
 */
interface PrefetchesExperience
{
    /** @param list<ExperienceQuery> $queries */
    public function prefetch(array $queries): void;
}
