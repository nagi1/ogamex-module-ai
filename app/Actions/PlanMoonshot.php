<?php

namespace Modules\AI\Actions;

/**
 * Emits the canonical moonshot plan as data.
 *
 * The plan is a sequence of independent attempts that all share the same
 * target composition, so it only ever carries the per-attempt moonchance:
 * a 20% ceiling does not accumulate, and the payload must not claim a moon
 * is guaranteed after any number of tries.
 */
class PlanMoonshot
{
    private const DEFENDER_SHIP = 'light_fighter';

    private const DEFENDER_COUNT = 1667;

    private const ATTACKER_SHIP = 'cruiser';

    private const ATTACKER_COUNT = 350;

    private const MAX_MOONCHANCE = 20;

    /**
     * @return array{
     *     defender: array{ship: string, count: int},
     *     attacker: array{ship: string, count: int},
     *     max_moonchance: int,
     *     attempts: list<array{
     *         attacker: array{ship: string, count: int},
     *         defender: array{ship: string, count: int},
     *         moonchance: int
     *     }>
     * }
     */
    public function execute(int $attempts = 1): array
    {
        $defender = [
            'ship' => self::DEFENDER_SHIP,
            'count' => self::DEFENDER_COUNT,
        ];

        // 350 cruisers is one attacker option that wins and still leaves
        // wreckage; the plan states it as an option, not as the only force.
        $attacker = [
            'ship' => self::ATTACKER_SHIP,
            'count' => self::ATTACKER_COUNT,
        ];

        $sequence = [];

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $sequence[] = [
                'attacker' => $attacker,
                'defender' => $defender,
                'moonchance' => self::MAX_MOONCHANCE,
            ];
        }

        return [
            'defender' => $defender,
            'attacker' => $attacker,
            'max_moonchance' => self::MAX_MOONCHANCE,
            'attempts' => $sequence,
        ];
    }
}
