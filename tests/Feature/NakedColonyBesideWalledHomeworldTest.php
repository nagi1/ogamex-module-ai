<?php

use Modules\AI\Domain\Decision\FacilityChain;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The bound the rule keeps: while the account stands no wall anywhere it is still in its opening, so
// the bare colony's chain is the usual one -- no facility is hoisted for a sibling that holds nothing.
test('a bare colony with no wall anywhere in the account is chained as usual', function (): void {
    $colony = $this->secondPlanetService ?? throw new LogicException('the account must own a colony for this story.');
    $colony->addResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));

    // A movable hull but no defence at all: the account is past nothing.
    $this->planetAddUnit('small_cargo', 1);

    $wanted = walledSiblingWallFacilities();
    $chain = walledSiblingChainNames($colony);

    expect($wanted)->not->toBeEmpty('the host must offer a defence unit to chain for')
        ->and(array_intersect($wanted, $chain))->toBeEmpty('with no wall anywhere the chain must be unchanged, but it was: [' . implode(', ', $chain) . ']');
});

// And a planet that already holds the wall is never handed the wall's own facility: the rule moves
// only the naked planet.
test('a walled planet is not chained to the facility its own wall already needed', function (): void {
    $this->planetAddUnit('light_laser', 1);
    $this->planetAddUnit('small_cargo', 1);

    $wanted = walledSiblingWallFacilities();
    $chain = walledSiblingChainNames($this->planetService);

    expect(array_intersect($wanted, $chain))->toBeEmpty('a planet holding a wall must not be chained to the wall facility, but its chain is: [' . implode(', ', $chain) . ']');
});

/** @return list<string> the machine names of the facilities the host's cheapest defence unit needs */
function walledSiblingWallFacilities(): array
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

/** @return list<string> the machine names of every step the planet's own chain proposes */
function walledSiblingChainNames(PlanetService $planet): array
{
    return array_map(
        static fn ($candidate): string => explode(':', $candidate->reason, 2)[1] ?? '',
        app(FacilityChain::class)->pending($planet),
    );
}
