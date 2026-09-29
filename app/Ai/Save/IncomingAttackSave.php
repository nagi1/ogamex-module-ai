<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Save;

/**
 * Order to take a planet's parked fleet off the planet before a hostile
 * inbound lands.
 *
 * The mechanism that carries the fleet out (deploy to an own planet, harvest,
 * a cancellable build) is not fixed here: the plan leaves it contested, so the
 * order states only that this fleet leaves this planet. Declared beside the
 * rule because the rule's file is the only file the plan gives it.
 */
final class FleetSaveAction
{
    /**
     * @param  array<string, int>  $ships  ship key => count, the fleet that leaves
     */
    public function __construct(
        public readonly int $planetId,
        public readonly array $ships,
    ) {
    }

    /**
     * Fleet still parked at the origin once this order has been carried out.
     *
     * @param  array<string, int>  $parkedShips
     * @return array<string, int>
     */
    public function apply(array $parkedShips): array
    {
        $remaining = [];

        foreach ($parkedShips as $shipKey => $count) {
            $left = $count - ($this->ships[$shipKey] ?? 0);

            if ($left > 0) {
                $remaining[$shipKey] = $left;
            }
        }

        return $remaining;
    }
}

/**
 * Saves a planet's parked fleet while a hostile fleet is inbound to it.
 *
 * The inbound is the only trigger, so the rule stays silent whenever no
 * hostile fleet is inbound and never disturbs normal operations. Defensive
 * structures are deliberately not read: the case this rule exists for holds
 * none, so nothing at the planet can absorb the hit and the save is the whole
 * loss mitigation.
 */
final class IncomingAttackSave
{
    /**
     * @param  array<string, int>  $parkedShips  ship key => count parked at the planet
     * @param  list<int>  $hostileInboundFleetIds  hostile fleets inbound to the planet
     * @return list<FleetSaveAction>
     */
    public function decide(int $planetId, array $parkedShips, array $hostileInboundFleetIds): array
    {
        if ($hostileInboundFleetIds === []) {
            return [];
        }

        if ($parkedShips === []) {
            return [];
        }

        // One save per parked fleet: a further hostile inbound does not add a
        // second save, the first has already taken the fleet off the planet.
        return [new FleetSaveAction($planetId, $parkedShips)];
    }
}
