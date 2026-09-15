<?php

namespace Modules\AI\Domain\Decision;

/**
 * The save that fails: a deliberate, named skip of a save the account could have taken.
 *
 * A 100% save rate over months is itself the observable that would give a cohort away, so a save
 * occasionally does not happen even though it was possible. The rate is a placeholder — no source
 * quantifies how often real players fail, and the plan's survey confirmed none — realised as one
 * named ordinary mistake (the "overnight gamble"): the account notices the inbound fleet and
 * decides, just this once, not to move.
 *
 * The draw is deterministic per account and inbound fleet, so a re-plan of the same decision
 * repeats it rather than flapping between "save" and "skip". The denominator is the mid-point of
 * the plan's 1-in-20-to-50 band and is to be replaced by telemetry from the capacity runs.
 */
class SaveFailurePolicy
{
    /** One failure per this many save opportunities (placeholder inside the plan's 20–50 band). */
    private const DENOMINATOR = 30;

    public function shouldSkip(?int $seed, int $key): ?string
    {
        if ($seed === null) {
            return null;
        }

        return (int) sprintf('%u', crc32('save-failure:' . $seed . ':' . $key)) % self::DENOMINATOR === 0
            ? 'overnight_gamble'
            : null;
    }
}
