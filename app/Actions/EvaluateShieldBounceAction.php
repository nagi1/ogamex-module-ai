<?php

namespace Modules\AI\Actions;

/**
 * Decides whether a single shot is bounced off a target unit's shield.
 *
 * The host compares each shot on its own — never a summed volley — so the AI
 * needs the verdict per shot before it can rank an attack or plan a defence
 * layer. Damage lands only when
 * attack·(1+0.1·WT) ≥ 0.01·maxShield·(1+0.1·ST); scaling both sides by 1000
 * keeps the comparison exact in integers, so a shot sitting exactly on the
 * threshold is not bounced.
 */
final class EvaluateShieldBounceAction
{
    public function execute(
        int $attack,
        int $weaponsTechnology,
        int $maxShield,
        int $shieldingTechnology,
    ): bool {
        return $attack * 100 * (10 + $weaponsTechnology) < $maxShield * (10 + $shieldingTechnology);
    }
}
