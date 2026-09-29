<?php

namespace Modules\AI\Domain\Attack;

/**
 * Counts the fleets sent at one target during a single in-game day.
 *
 * The budget is held per planet/moon, so a planet and the moon at the same
 * coordinates never consume each other's attacks, and the count is not shared
 * across coordinates the way a per-player counter would be.
 */
final class DailyAttackBudget
{
    /**
     * Attacks (fleets) permitted per planet/moon per day, per the wave rules.
     */
    public const MAX_ATTACKS_PER_TARGET_PER_DAY = 8;

    /** @var array<string, int> */
    private array $spent = [];

    /**
     * Claims one attack slot for the target.
     *
     * @return bool true while the target is within budget, false once it is used up.
     */
    public function spend(int $galaxy, int $system, int $position, bool $isMoon): bool
    {
        if (! $this->allows($galaxy, $system, $position, $isMoon)) {
            return false;
        }

        $key = $this->key($galaxy, $system, $position, $isMoon);

        $this->spent[$key] = ($this->spent[$key] ?? 0) + 1;

        return true;
    }

    public function allows(int $galaxy, int $system, int $position, bool $isMoon): bool
    {
        return $this->spentOn($galaxy, $system, $position, $isMoon) < self::MAX_ATTACKS_PER_TARGET_PER_DAY;
    }

    public function spentOn(int $galaxy, int $system, int $position, bool $isMoon): int
    {
        return $this->spent[$this->key($galaxy, $system, $position, $isMoon)] ?? 0;
    }

    private function key(int $galaxy, int $system, int $position, bool $isMoon): string
    {
        return $galaxy . ':' . $system . ':' . $position . ':' . ($isMoon ? 'moon' : 'planet');
    }
}
