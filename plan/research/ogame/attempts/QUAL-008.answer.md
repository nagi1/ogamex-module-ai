### EDIT: app/Domain/Decision/QueueableColonyPlanner.php
<<<<<<< SEARCH
        $origin = $this->colonyShipPlanet($player->planets->all());
        if ($origin === null) {
            return null;
        }
=======
        $origin = $this->originPlanet($player->planets->all());
        if ($origin === null) {
            return null;
        }
>>>>>>> REPLACE

<<<<<<< SEARCH
    /**
     * The planet carrying an idle colony ship.
     *
     * @param array<int, PlanetService> $planets
     */
    private function colonyShipPlanet(array $planets): ?PlanetService
    {
        foreach ($planets as $planet) {
            if ($planet->getShipUnits()->getAmountByMachineName(ColonisationMission::getRequiredShipMachineNames()[0]) > 0) {
                return $planet;
            }
        }

        return null;
    }
=======
    /**
     * The planet a colony leaves from: the one holding a colony ship, else the account's first.
     *
     * Waiting for the ship before committing to a free slot is what left the cohort never
     * colonising: the slot is claimed by deciding to settle it, and the ship a shipyard builds
     * for that decision belongs to the same decision rather than to its precondition. The host
     * still owns the ship's machine name and the reach of the walk.
     *
     * @param array<int, PlanetService> $planets
     */
    private function originPlanet(array $planets): ?PlanetService
    {
        $ship = ColonisationMission::getRequiredShipMachineNames()[0];

        foreach ($planets as $planet) {
            if ($planet->getShipUnits()->getAmountByMachineName($ship) > 0) {
                return $planet;
            }
        }

        $first = array_key_first($planets);

        return $first === null ? null : $planets[$first];
    }
>>>>>>> REPLACE

### FILE: tests/Feature/Situations/ColonisationWithoutShipTest.php
```php
<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// The fast proof settles a planet with a colony ship already on hand. These are the two edges
// around it: the account whose ship is still to be built, and the account with no slot left.

test('a free colony slot is claimed even before the colony ship is built', function (): void {
    Situation::of($this)
        ->research('astrophysics', 4)
        ->research('impulse_drive', 3)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectWork(AiWorkKind::Colonize);
});

test('an account whose colony slots are exhausted plans no colony', function (): void {
    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectNoWork(AiWorkKind::Colonize);
});
```