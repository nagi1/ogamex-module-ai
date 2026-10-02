<?php

use Modules\AI\Domain\Decision\FacilityChain;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// QUAL-003: the cohort found a planet at zero defence while a sibling held a wall. The unit planner
// offers such a planet the doctrine's floor, but the host only accepts a defence order where the yard
// stands -- so a bare colony beside a walled sibling needs the facility that gates the wall as a
// building step of its own. The facility is never named: it comes from the host's own recursive
// requirement graph for the cheapest defence the host offers.
test('a bare colony beside a walled sibling is chained to the facilities its wall needs', function (): void {
    $colony = $this->secondPlanetService ?? throw new LogicException('the account must own a colony for this story.');
    $colony->addResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));

    // The sibling's wall, and a movable hull on it, so the account is not fleetless and the colony's
    // own ambition is not a hull whose requirements already carry the yard.
    $this->planetAddUnit('light_laser', 1);
    $this->planetAddUnit('small_cargo', 1);

    $wanted = nakedColonyWallFacilities();
    $chain = nakedColonyChainNames($colony);

    expect($wanted)->not->toBeEmpty('the host must offer a defence unit to chain for')
        ->and(array_intersect($wanted, $chain))->not->toBeEmpty('a bare colony beside a walled sibling must be chained to the facilities its wall needs, but its chain is: [' . implode(', ', $chain) . ']');
});

/** @return list<string> the machine names of the facilities the host's cheapest defence unit needs */
function nakedColonyWallFacilities(): array
{
    $cheapest = null;
    $cheapestPrice = INF;

    foreach (ObjectService::getDefenseObjects() as $defence) {
        $price = ObjectService::getObjectRawPrice($defence->machine_name)->sum();
        if ($price <= 0 || $price >= $cheapestPrice) {
            continue;
        }

        $cheapest = $defence;
        $cheapestPrice = $price;
    }

    return $cheapest === null ? [] : array_keys(ObjectService::getRecursiveRequirements($cheapest->machine_name));
}

/** @return list<string> the machine names of every step the colony's own chain proposes */
function nakedColonyChainNames(PlanetService $colony): array
{
    return array_map(
        static fn ($candidate): string => explode(':', $candidate->reason, 2)[1] ?? '',
        app(FacilityChain::class)->pending($colony),
    );
}
