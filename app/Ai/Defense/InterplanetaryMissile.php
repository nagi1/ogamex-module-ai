<?php

namespace Modules\AI\Ai\Defense;

/**
 * Read-only rule figures for the Interplanetary Missile.
 *
 * The figures are returned as literals rather than read from host config or derived
 * from another table, so no rounding, scaling or config drift can change them.
 */
final readonly class InterplanetaryMissile
{
    /**
     * The source states "5 Interplanetary Missiles for every Missile Silo Level".
     */
    private const STORAGE_PER_SILO_LEVEL = 5;

    /**
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    public function cost(): array
    {
        return [
            'metal' => 12500,
            'crystal' => 2500,
            'deuterium' => 10000,
        ];
    }

    public function shieldPower(): int
    {
        return 1;
    }

    /**
     * The source states the per-level ratio only and names neither a cap nor a lower
     * bound, so neither is applied here; a host may still cap stored counts elsewhere.
     */
    public function storageCapacityForSiloLevel(int $missileSiloLevel): int
    {
        return self::STORAGE_PER_SILO_LEVEL * $missileSiloLevel;
    }
}
