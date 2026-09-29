<?php

declare(strict_types=1);

namespace Modules\AI\Ai;

use Carbon\CarbonImmutable;

/**
 * The facts of a single destroyed-probe event: who probed us, and when the probes were destroyed.
 *
 * Only the two facts the AI is handed are kept. The host states no counterespionage mechanic, so
 * nothing is derived from them: no probability, no threshold, no ratio.
 */
final class CounterespionageObservation
{
    public function __construct(
        public readonly int $attackerId,
        public readonly CarbonImmutable $observedAt,
    ) {
    }

    /**
     * Record an event reported by our defenses, keeping the attacker and the timestamp verbatim.
     */
    public static function fromDestroyedProbes(int $attackerId, CarbonImmutable $destroyedAt): self
    {
        return new self($attackerId, $destroyedAt);
    }
}
