<?php

namespace Modules\AI\Actions;

/**
 * Proposes the single WIK-046 Jump Gate relocation, always between two of the player's own moons.
 *
 * WIK-046 is community-documented with medium confidence and lists `9` under a `Cost` header; the
 * page does not settle whether that cell is the structure build cost or the per-jump price. The
 * action therefore records the verbatim figure as an attributed fact and never prices a jump from
 * moon distance or from a recomputed value.
 */
final class EvaluateJumpGate
{
    /**
     * WIK-046 `Cost` cell, recorded verbatim as the one documented figure.
     */
    public const DOCUMENTED_COST = 9;

    public const DOCUMENTED_COST_SOURCE = 'WIK-046';

    /**
     * @param  list<int|string>  $ownMoonIds  the player's own moons, in caller order.
     * @return array{type: string, from: int|string, to: int|string, cost: int, cost_source: string, advisory: bool}|null
     */
    public function propose(array $ownMoonIds): ?array
    {
        // A Jump Gate has no valid target without a second own moon.
        if (count($ownMoonIds) < 2) {
            return null;
        }

        return [
            'type' => 'jump_gate_relocation',
            'from' => $ownMoonIds[0],
            'to' => $ownMoonIds[1],
            'cost' => self::DOCUMENTED_COST,
            'cost_source' => self::DOCUMENTED_COST_SOURCE,
            // Jumps burn deuterium that may be needed elsewhere, so the proposal stays advisory.
            'advisory' => true,
        ];
    }
}
