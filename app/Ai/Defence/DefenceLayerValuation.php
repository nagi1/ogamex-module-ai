<?php

namespace Modules\AI\Ai\Defence;

/**
 * Post-attack defence rebuild and endgame layering valuation.
 *
 * 70% of destroyed defence regenerates after an attack, so the AI budgets only the
 * 30% gap before layering shield domes and Plasma Turrets — and that layering spend
 * waits for Anti-Ballistic Missile cover. Unit costs are supplied by callers: no
 * defence cost table lives here.
 */
final class DefenceLayerValuation
{
    /**
     * Share of a destroyed unit's build cost the AI has to pay to restore it.
     */
    private const REBUILD_SHARE_PERCENT = 30;

    /**
     * Anti-Ballistic Missiles required per observed Interplanetary Missile.
     */
    private const ANTI_BALLISTIC_MISSILES_PER_MISSILE = 1;

    /**
     * The source's only build-order number, and it is explicitly loose: a soft
     * target for the endgame queue, never a gate on its own.
     */
    private const PLASMA_TURRET_SOFT_TARGET = 50;

    private const SHIELD_DOME_PER_LAYER = 1;

    /**
     * Resources the AI must spend to restore the destroyed defence — the 30% gap
     * left after regeneration, never the full rebuild price.
     *
     * @param  array<string, array{metal: int, crystal: int, deuterium?: int, destroyed: int}>  $losses
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    public function rebuildBudget(array $losses): array
    {
        $budget = ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];

        foreach ($losses as $loss) {
            $destroyed = $loss['destroyed'];
            $budget['metal'] += $this->rebuildShare($loss['metal'] * $destroyed);
            $budget['crystal'] += $this->rebuildShare($loss['crystal'] * $destroyed);
            $budget['deuterium'] += $this->rebuildShare(($loss['deuterium'] ?? 0) * $destroyed);
        }

        return $budget;
    }

    /**
     * Anti-Ballistic Missiles needed against observed incoming Interplanetary
     * Missiles — one for one.
     */
    public function requiredAntiBallisticMissiles(int $observedInterplanetaryMissiles): int
    {
        return $observedInterplanetaryMissiles * self::ANTI_BALLISTIC_MISSILES_PER_MISSILE;
    }

    /**
     * Shield dome and Plasma Turret layering starts once ABM cover matches the
     * observed missiles; while the sky is open the layering spend stays withheld.
     */
    public function layeringUnlocked(int $observedInterplanetaryMissiles, int $antiBallisticMissiles): bool
    {
        return $antiBallisticMissiles >= $this->requiredAntiBallisticMissiles($observedInterplanetaryMissiles);
    }

    /**
     * Endgame layering queue: the loose 50+ Plasma Turret advice together with one
     * Small and one Large Shield Dome, released only once the ABM gate is satisfied.
     *
     * @return array<string, int>
     */
    public function endgameLayeringQueue(int $observedInterplanetaryMissiles, int $antiBallisticMissiles): array
    {
        if (! $this->layeringUnlocked($observedInterplanetaryMissiles, $antiBallisticMissiles)) {
            return [];
        }

        return [
            'plasma_turret' => self::PLASMA_TURRET_SOFT_TARGET,
            'small_shield_dome' => self::SHIELD_DOME_PER_LAYER,
            'large_shield_dome' => self::SHIELD_DOME_PER_LAYER,
        ];
    }

    /**
     * Resources are whole units, so a share that does not divide evenly truncates
     * instead of rounding up into spend the AI cannot afford.
     */
    private function rebuildShare(int $buildCost): int
    {
        return intdiv($buildCost * self::REBUILD_SHARE_PERCENT, 100);
    }
}
