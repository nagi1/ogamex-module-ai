<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Fleet;

/**
 * Ordering distance between two OGame coordinates.
 *
 * The wiki documents the concept of a coordinate distance but states no
 * galaxy/system/position weighting, and this module owns no fleet numbers, so no
 * weights are applied here: the helper only encodes that identical coordinates are
 * zero apart and that the distance grows with the coordinate delta. Ship speeds,
 * drives and consumption stay with their existing owners.
 */
final class FlightDistance
{
    public static function between(
        int $fromGalaxy,
        int $fromSystem,
        int $fromPosition,
        int $toGalaxy,
        int $toSystem,
        int $toPosition,
    ): int {
        return abs($toGalaxy - $fromGalaxy)
            + abs($toSystem - $fromSystem)
            + abs($toPosition - $fromPosition);
    }
}
