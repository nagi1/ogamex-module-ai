<?php

declare(strict_types=1);

namespace Modules\AI\Defense;

/**
 * The build order for a Fodder Heavy wall: the fodder tier first, then what the fodder buy.
 *
 * The order is the whole point of the slice. The fodder is the only unit in the wall cheap enough
 * to be replaced between raids and the only one whose loss does not cost the account a turret, so
 * an account that queues the support units first spends its early metal on guns it cannot cover
 * and meets the same fleet with fewer fodder slots. The planner therefore emits the tier the
 * doctrine declares as fodder ahead of the support tier and never interleaves the two; which unit
 * that is stays the doctrine's fact, so a doctrine that changes its fodder changes it in one place.
 */
final class DefenseCompositionPlanner
{
    /**
     * @return array<string, int> unit key => target count, in build order
     */
    public function plan(int $fodder): array
    {
        $doctrine = FodderHeavyDefenseDoctrine::forFodder($fodder);

        return [
            ...$doctrine->fodderTier(),
            ...$doctrine->supportTier(),
        ];
    }

    /**
     * Anti-ballistic cover is a silo question, not a ratio one: the doctrine can only say "fill
     * what the silo holds", so the flag is handed on instead of a count invented here.
     */
    public function maximizesAntiBallisticMissiles(int $fodder): bool
    {
        return FodderHeavyDefenseDoctrine::forFodder($fodder)->antiBallisticMissilesMaximized;
    }
}
