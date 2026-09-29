<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Formula;

/**
 * Why: the module previously had no opinion on build or research durations and the
 * only source is the community wiki (documented, medium confidence). The two formulas
 * are therefore transcribed literally and left as floats, so sub-hour results survive;
 * officers, boosters and lifeform modifiers are deliberately not folded in here until
 * the host confirms the formulas, and this class must not silently become the only authority.
 */
final class ConstructionTimeCalculator
{
    public static function buildingHours(
        float $metal,
        float $crystal,
        int $roboticFactoryLevel,
        int $naniteFactoryLevel,
        float $universeSpeed,
    ): float {
        return ($metal + $crystal)
            / (2500 * (1 + $roboticFactoryLevel) * $universeSpeed * 2 ** $naniteFactoryLevel);
    }

    public static function researchHours(
        float $metal,
        float $crystal,
        int $researchLabLevel,
        float $universeSpeed,
    ): float {
        return ($metal + $crystal) / (1000 * (1 + $researchLabLevel * $universeSpeed));
    }
}
