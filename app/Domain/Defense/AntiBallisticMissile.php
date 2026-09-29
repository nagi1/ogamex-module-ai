<?php

namespace Modules\AI\Domain\Defense;

/**
 * Anti-ballistic missile facts.
 *
 * Section 4 of the wiki (0.78a) has no ABM entry, so the contested stats are
 * kept behind this type and read from one place instead of being re-derived.
 */
final class AntiBallisticMissile
{
    public const COST_METAL = 8000;

    public const COST_CRYSTAL = 0;

    public const COST_DEUTERIUM = 2000;

    public const STRUCTURAL_INTEGRITY = 8000;

    public const SHIELD = 1;

    public const WEAPON = 1;

    public const MISSILE_SILO_LEVEL_PREREQUISITE = 2;

    public const STORAGE_PER_SILO_LEVEL = 10;

    /** WIK-025 stores 5 IPM per silo level, half of this weapon's 10 ABM per level. */
    public const IPM_TO_ABM_CAPACITY_RATIO = [1, 2];

    public static function capacityFor(int $siloLevel): int
    {
        return $siloLevel * self::STORAGE_PER_SILO_LEVEL;
    }

    public static function intercepts(int $ipmCount): int
    {
        return $ipmCount;
    }
}
