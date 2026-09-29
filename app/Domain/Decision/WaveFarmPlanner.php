<?php

namespace Modules\AI\Domain\Decision;

/**
 * Splits a raid on one target into a frontline wave and a follow-up re-plunder wave.
 *
 * The source fixes the split at 7:5 military units and caps each wave's cargo at half of
 * the stock still stored when that wave lands. No combat technology is ingested, so one
 * composition unit is valued as one enemy military unit and wave one is sized at the
 * defender's fleet plus the 30% of its defense the 70% survival figure leaves exposed.
 */
final class WaveFarmPlanner
{
    private const FRONTLINE_SHARE = 7;

    private const FOLLOW_UP_SHARE = 5;

    private const LIGHT_FIGHTER_SHARE = 5;

    private const HEAVY_FIGHTER_SHARE = 1;

    private const DEFENSE_SURVIVAL_PERCENT = 70;

    private const PLUNDER_CAP_PERCENT = 50;

    /**
     * @return list<array{
     *     wave: int,
     *     light_fighters: int,
     *     heavy_fighters: int,
     *     stored: array{metal: int, crystal: int, deuterium: int},
     *     cargo: array{metal: int, crystal: int, deuterium: int}
     * }>
     */
    public function plan(
        int $fleetUnits,
        int $defenseUnits,
        int $metal,
        int $crystal,
        int $deuterium,
    ): array {
        $frontlineUnits = $fleetUnits + intdiv($defenseUnits * (100 - self::DEFENSE_SURVIVAL_PERCENT), 100);
        $followUpUnits = intdiv($frontlineUnits * self::FOLLOW_UP_SHARE, self::FRONTLINE_SHARE);

        $frontline = $this->wave(1, $frontlineUnits, $metal, $crystal, $deuterium);
        $followUp = $this->wave(
            2,
            $followUpUnits,
            $metal - $frontline['cargo']['metal'],
            $crystal - $frontline['cargo']['crystal'],
            $deuterium - $frontline['cargo']['deuterium'],
        );

        return [$frontline, $followUp];
    }

    /**
     * @return array{
     *     wave: int,
     *     light_fighters: int,
     *     heavy_fighters: int,
     *     stored: array{metal: int, crystal: int, deuterium: int},
     *     cargo: array{metal: int, crystal: int, deuterium: int}
     * }
     */
    private function wave(int $number, int $units, int $metal, int $crystal, int $deuterium): array
    {
        $lightFighters = intdiv($units * self::LIGHT_FIGHTER_SHARE, self::LIGHT_FIGHTER_SHARE + self::HEAVY_FIGHTER_SHARE);

        return [
            'wave' => $number,
            'light_fighters' => $lightFighters,
            'heavy_fighters' => $units - $lightFighters,
            'stored' => ['metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deuterium],
            'cargo' => $this->plunderCap($metal, $crystal, $deuterium),
        ];
    }

    /**
     * The 50% figure is the limit the source states, so a wave plans that share of the stock
     * in front of it and never empties the planet, however much is lying there.
     *
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function plunderCap(int $metal, int $crystal, int $deuterium): array
    {
        return [
            'metal' => intdiv($metal * self::PLUNDER_CAP_PERCENT, 100),
            'crystal' => intdiv($crystal * self::PLUNDER_CAP_PERCENT, 100),
            'deuterium' => intdiv($deuterium * self::PLUNDER_CAP_PERCENT, 100),
        ];
    }
}
