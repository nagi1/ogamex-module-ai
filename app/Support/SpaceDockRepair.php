<?php

namespace Modules\AI\Support;

/**
 * The source documents one number for space dock repair: 10% of the total fleet.
 * Combat and raid planning both need that share, so it lives here once instead of
 * being re-derived (and silently re-invented) at each call site.
 */
final class SpaceDockRepair
{
    /**
     * Documented repair share of the total fleet, in percent.
     */
    public const REPAIR_PERCENT = 10;

    private const PERCENT_SCALE = 100;

    private function __construct()
    {
    }

    /**
     * Whole fleet units the space dock restores.
     *
     * Ships are whole units and the source states no rounding rule for fleets that are
     * not a multiple of ten, so the share truncates: fewer than ten units repairs nothing.
     */
    public static function repairedUnits(int $fleetUnits): int
    {
        return intdiv($fleetUnits * self::REPAIR_PERCENT, self::PERCENT_SCALE);
    }

    /**
     * Whole fleet units that stay lost once the space dock has repaired.
     */
    public static function lostUnits(int $fleetUnits): int
    {
        return $fleetUnits - self::repairedUnits($fleetUnits);
    }

    /**
     * The same share as a ratio, for callers that scale a value instead of counting ships.
     */
    public static function repairFraction(): float
    {
        return self::REPAIR_PERCENT / self::PERCENT_SCALE;
    }
}
