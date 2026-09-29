<?php

namespace Modules\AI\Ai\Planner;

use Modules\AI\Domain\Attack\DailyAttackBudget;

/**
 * Decides whether another fleet may be sent at a target.
 *
 * Wave planning reads the target's daily budget instead of a per-player attack
 * count, because the eight attacks belong to the planet or moon being hit.
 */
final class AttackPlanner
{
    public function __construct(private readonly DailyAttackBudget $budget)
    {
    }

    public function canAttack(int $galaxy, int $system, int $position, bool $isMoon): bool
    {
        return $this->budget->allows($galaxy, $system, $position, $isMoon);
    }

    public function recordAttack(int $galaxy, int $system, int $position, bool $isMoon): bool
    {
        return $this->budget->spend($galaxy, $system, $position, $isMoon);
    }
}
