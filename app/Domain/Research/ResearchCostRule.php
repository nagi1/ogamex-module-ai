<?php

namespace Modules\AI\Domain\Research;

/**
 * Prices a research upgrade for the AI planner.
 *
 * The rule the module follows: each research level costs twice the level before
 * it, with Graviton Technology as the single exception - a flat energy price on
 * its only useful level.
 */
final class ResearchCostRule
{
    /**
     * Key of the exception technology. It is a plain string because the plan adds
     * no enum file for research technologies; the host binds its own identifier
     * here once the wiki figure is confirmed.
     */
    public const GRAVITON_TECHNOLOGY = 'graviton_technology';

    /** Graviton is paid in energy, not in metal/crystal/deuterium. */
    public const GRAVITON_ENERGY_COST = 300_000;

    public const GRAVITON_MAX_USEFUL_LEVEL = 1;

    /** Doubling is the rule for every technology that is not the exception. */
    private const COST_MULTIPLIER = 2;

    public function isGraviton(string $technology): bool
    {
        return $technology === self::GRAVITON_TECHNOLOGY;
    }

    /**
     * Highest level worth queueing, or null when the technology has no stated
     * ceiling.
     */
    public function maximumUsefulLevel(string $technology): ?int
    {
        if ($this->isGraviton($technology)) {
            return self::GRAVITON_MAX_USEFUL_LEVEL;
        }

        return null;
    }

    /**
     * Price of the level after $level, given the known price of $level.
     *
     * Null means the planner must not queue an upgrade: the technology has no
     * useful level left, or - at level 0 of a technology that is not the
     * exception - there is no previous level to double, and this rule never
     * invents a base price the game's own cost table owns.
     */
    public function costOfNextLevel(string $technology, int $level, int $priceOfLevel): ?int
    {
        if ($this->isGraviton($technology)) {
            return $this->gravitonNextLevelCost($level);
        }

        if ($level < 1) {
            return null;
        }

        return $priceOfLevel * self::COST_MULTIPLIER;
    }

    /**
     * Graviton's price is flat: its first level costs the full energy price and
     * nothing above the useful level is ever priced.
     */
    private function gravitonNextLevelCost(int $level): ?int
    {
        if ($level >= self::GRAVITON_MAX_USEFUL_LEVEL) {
            return null;
        }

        return self::GRAVITON_ENERGY_COST;
    }
}
