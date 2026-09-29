<?php

namespace Modules\AI\Ai\Doctrine\Defence;

/**
 * Ion Cannons are shield fodder, not attack power: each one absorbs 500 damage per round, so a
 * stack of them holds light and medium fleets to a draw instead of losing to them. Hulls of
 * Battleship class or heavier out-damage that absorption and erase the stack, so the same stack
 * must not be scored as shield fodder against them.
 */
final class IonCannonShieldFodder
{
    public const int SHIELD_ABSORBED_PER_ROUND = 500;

    /**
     * Hulls at Battleship class or heavier. Everything lighter stays inside the draw band.
     */
    private const array BATTLESHIP_CLASS_OR_HEAVIER = [
        'battleship',
        'bomber',
        'destroyer',
        'battlecruiser',
        'reaper',
        'deathstar',
    ];

    /**
     * Value of an Ion Cannon stack as shield fodder. Zero means the stack is erased rather than
     * drawing, so the caller must fall back to the ordinary defensive structure scoring.
     *
     * @param  list<string>  $attackerHullClasses
     */
    public function drawValue(int $ionCannonCount, array $attackerHullClasses): int
    {
        if ($this->isErasedBy($attackerHullClasses)) {
            return 0;
        }

        return $ionCannonCount * self::SHIELD_ABSORBED_PER_ROUND;
    }

    /**
     * @param  list<string>  $attackerHullClasses
     */
    public function isShieldFodder(int $ionCannonCount, array $attackerHullClasses): bool
    {
        return $this->drawValue($ionCannonCount, $attackerHullClasses) > 0;
    }

    /**
     * @param  list<string>  $attackerHullClasses
     */
    private function isErasedBy(array $attackerHullClasses): bool
    {
        return array_intersect($attackerHullClasses, self::BATTLESHIP_CLASS_OR_HEAVIER) !== [];
    }
}
