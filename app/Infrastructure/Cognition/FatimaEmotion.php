<?php

namespace Modules\AI\Infrastructure\Cognition;

/**
 * One appraised emotion reported by the FAtiMA driver.
 *
 * `causeEvent` is retained because the driver keeps an emotional pool that spans
 * recent appraisals. Matching on the event the module just perceived is what makes
 * the read deterministic instead of returning whatever was appraised most recently.
 */
readonly class FatimaEmotion
{
    public function __construct(
        public string $type,
        public float $intensity,
        public string $causeEvent,
    ) {
    }
}
