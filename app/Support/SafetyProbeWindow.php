<?php

namespace Modules\AI\Support;

use Carbon\CarbonImmutable;

/**
 * The two timestamps a deferred strike reads off its arrival time: the safety
 * probe sent right after the crash, and the delayed return leg.
 *
 * Both values are offsets from the caller's arrival time, so the helper stays
 * input-driven — 23:00 → 23:01/23:10 is only the worked example the source
 * documents, not the rule itself. The ACS slowdown timing is CONTESTED in the
 * source, so no ACS-facing value is derived here and callers must not read
 * this as a guarantee about ACS behaviour.
 */
final readonly class SafetyProbeWindow
{
    private const PROBE_OFFSET_MINUTES = 1;

    private const DELAYED_RETURN_OFFSET_MINUTES = 10;

    private function __construct(
        public CarbonImmutable $probeArrival,
        public CarbonImmutable $delayedReturn,
    ) {
    }

    public static function fromArrival(CarbonImmutable $arrival): self
    {
        return new self(
            probeArrival: $arrival->addMinutes(self::PROBE_OFFSET_MINUTES),
            delayedReturn: $arrival->addMinutes(self::DELAYED_RETURN_OFFSET_MINUTES),
        );
    }
}
