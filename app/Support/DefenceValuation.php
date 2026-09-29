<?php

namespace Modules\AI\Support;

use InvalidArgumentException;

/**
 * Pure calculator for the values the community wiki states for a defence
 * candidate (WIK-038, lines 22-25): a cost weighted 2.5 metal : 1.5 crystal :
 * 1 deuterium, plus the candidate's integrity, shield and weapon per 1000
 * units of that cost. Every number comes from the caller, so no per-defence
 * data is baked in here.
 */
final class DefenceValuation
{
    private const WEIGHT_METAL = 2.5;
    private const WEIGHT_CRYSTAL = 1.5;
    private const WEIGHT_DEUTERIUM = 1.0;
    private const SCALE = 1000;

    /**
     * @return array{cost: float, integrity: float, shield: float, weapon: float}
     */
    public static function ratios(
        int $metal,
        int $crystal,
        int $deuterium,
        int $integrity,
        int $shield,
        int $weapon,
    ): array {
        $cost = $metal * self::WEIGHT_METAL
            + $crystal * self::WEIGHT_CRYSTAL
            + $deuterium * self::WEIGHT_DEUTERIUM;

        if ($cost === 0.0) {
            // The cost is the shared denominator of all three ratios, so a free candidate has no value to report.
            throw new InvalidArgumentException('A defence candidate with zero weighted cost cannot be valued.');
        }

        return [
            'cost' => $cost,
            'integrity' => self::SCALE * $integrity / $cost,
            'shield' => self::SCALE * $shield / $cost,
            'weapon' => self::SCALE * $weapon / $cost,
        ];
    }
}
