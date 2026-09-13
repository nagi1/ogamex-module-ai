<?php

namespace Modules\AI\Infrastructure\Cognition;

/**
 * The OCC values one stimulus contributes, already signed.
 *
 * Signing here rather than in the scenario is a driver constraint: a FAtiMA rule
 * cannot negate a variable, so "-[d]" is rejected as an ill-formed name.
 */
readonly class FatimaStimulusBranch
{
    public function __construct(
        public string $action,
        public float $desirability,
        public float $threat,
    ) {
    }
}
