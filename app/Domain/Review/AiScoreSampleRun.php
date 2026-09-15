<?php

namespace Modules\AI\Domain\Review;

use Carbon\CarbonImmutable;

/**
 * What one sampling pass did, so an operator can tell an hour that recorded everything from an
 * hour that found nothing to record.
 *
 * The counts are reported rather than logged: a pass that skips accounts is the normal state of a
 * young universe, where the host has not written a score row yet, so the figure is information
 * rather than a warning.
 */
readonly class AiScoreSampleRun
{
    public function __construct(
        public int $sampled = 0,
        public int $skipped = 0,
        public CarbonImmutable|null $sampledAt = null,
    ) {
    }
}
