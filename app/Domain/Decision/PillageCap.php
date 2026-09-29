<?php

namespace Modules\AI\Domain\Decision;

/**
 * Caps raid loot: at most half of each resource the defender holds, and fleet
 * cargo capacity can only lower that figure — where the two limits disagree
 * the cargo cap wins, the half is never a floor.
 *
 * WIK-039's headline mentions rolling for defence rebuilds; the validated plan
 * scopes that out (no section owns defence-rebuild) and binds only the pillage
 * cap, so nothing else lives here.
 */
final class PillageCap
{
    /**
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    public static function apply(int $metal, int $crystal, int $deuterium, int $cargoCapacity): array
    {
        // The stated capacity bounds each resource on its own: a 100,000
        // capacity takes 100,000 metal and 100,000 crystal from a rich stock.
        return [
            'metal' => self::take($metal, $cargoCapacity),
            'crystal' => self::take($crystal, $cargoCapacity),
            'deuterium' => self::take($deuterium, $cargoCapacity),
        ];
    }

    /**
     * Flooring the half keeps the take from creeping over half when a stock is
     * odd; the source states the cap, never the rounding.
     */
    private static function take(int $stock, int $cargoCapacity): int
    {
        return min(intdiv($stock, 2), $cargoCapacity);
    }
}
