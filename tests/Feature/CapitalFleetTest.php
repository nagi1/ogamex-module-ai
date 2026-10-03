<?php

use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// LIFE-002: an account with a yard and nothing urgent grows a war fleet from the strongest military hull the
// host lets it build, so large battles and, once research allows, death stars appear without the module naming one.

function capitalProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7,
        'enabled' => true,
    ]);
}

test('a rich account with a capable yard orders the strongest military hull it can build', function (): void {
    capitalProfile($this->currentUserId);
    $this->planetAddResources(new Resources(50_000_000, 30_000_000, 20_000_000));
    $this->planetSetObjectLevel('shipyard', 8);
    $this->planetSetObjectLevel('robot_factory', 10);
    $this->playerSetResearchLevel('combustion_drive', 6);
    $this->playerSetResearchLevel('impulse_drive', 6);
    $this->playerSetResearchLevel('weapon_technology', 6);
    $this->playerSetResearchLevel('shielding_technology', 6);
    $this->playerSetResearchLevel('armor_technology', 6);
    $this->planetAddUnit('large_cargo', 5);
    $this->planetAddUnit('small_cargo', 5);
    $this->planetAddUnit('espionage_probe', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('light_fighter', 1);
    $this->planetAddUnit('rocket_launcher', 20_000);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toStartWith('role:capital:');

    // The hull is the dearest the host lets this planet build, read from the catalogue's own prices.
    $dearest = 0.0;
    foreach (ObjectService::getMilitaryShipObjects() as $hull) {
        if (ObjectService::objectRequirementsMet($hull->machine_name, $this->planetService) && $hull->machine_name !== 'espionage_probe') {
            $price = ObjectService::getObjectPrice($hull->machine_name, $this->planetService);
            $dearest = max($dearest, $price->metal->get() + $price->crystal->get() + $price->deuterium->get());
        }
    }
    $chosen = ObjectService::getObjectPrice(str_replace('role:capital:', '', $plan->reason), $this->planetService);

    expect($chosen->metal->get() + $chosen->crystal->get() + $chosen->deuterium->get())->toBe($dearest)
        ->and($plan->amount)->toBeGreaterThan(1);
});

test('a poor account with the same yard orders no capital hull', function (): void {
    capitalProfile($this->currentUserId);
    $this->planetAddResources(new Resources(500, 500, 0));
    $this->planetSetObjectLevel('shipyard', 8);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan === null || ! str_starts_with($plan->reason, 'role:capital:'))->toBeTrue();
});

test('the dearest hull comes from the yard that unlocks it, not from the richest planet', function (): void {
    capitalProfile($this->currentUserId);
    // The homeworld is the account's richest planet but its yard is too small for the heavy hulls,
    // while the colony has the yard and less stock: the war fleet has to be ordered where the host
    // lets it be built, or an account keeps a rich planet's light hulls forever.
    $this->planetAddResources(new Resources(50_000_000, 30_000_000, 20_000_000));
    $this->planetSetObjectLevel('shipyard', 5);
    $this->planetSetObjectLevel('robot_factory', 10);
    // The technologies the heavy hulls wait on, so only the yard's size separates the two planets.
    foreach ([
        'combustion_drive' => 6,
        'impulse_drive' => 6,
        'weapon_technology' => 6,
        'shielding_technology' => 6,
        'armor_technology' => 6,
        'hyperspace_drive' => 6,
        'hyperspace_technology' => 5,
        'ion_technology' => 2,
    ] as $research => $level) {
        $this->playerSetResearchLevel($research, $level);
    }
    capitalStandingUnits($this->planetService);

    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    app(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer($player, $this->getNearbyEmptyCoordinate());

    $colonyId = (int) Planet::query()->where('user_id', $this->currentUserId)
        ->where('planet_type', 1)->where('id', '!=', $this->currentPlanetId)->orderByDesc('id')->value('id');
    $colony = app(PlanetServiceFactory::class)->make($colonyId, true);
    $colony->addResources(new Resources(8_000_000, 6_000_000, 3_000_000));
    $colony->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 10, true);
    $colony->setObjectLevel(ObjectService::getObjectByMachineName('robot_factory')->id, 10, true);
    capitalStandingUnits($colony);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toStartWith('role:capital:')
        ->and($plan->planetId)->toBe($colonyId);

    $dearestOnHome = capitalDearestHullPrice($this->planetService);
    $dearestOnColony = capitalDearestHullPrice($colony);
    $chosen = ObjectService::getObjectPrice(str_replace('role:capital:', '', $plan->reason), $colony);

    expect($dearestOnColony)->toBeGreaterThan($dearestOnHome)
        ->and($chosen->sum())->toBe($dearestOnColony);
});

/** The opening fleet every squadron role waits on, plus the wall that keeps the standing pass quiet. */
function capitalStandingUnits(PlanetService $planet): void
{
    foreach (['small_cargo' => 5, 'espionage_probe' => 1, 'colony_ship' => 1, 'rocket_launcher' => 20_000] as $machineName => $amount) {
        $planet->addUnit($machineName, $amount);
    }
}

/** The dearest fighting hull the host lets this planet build and pay for, read from the catalogue itself. */
function capitalDearestHullPrice(PlanetService $planet): float
{
    $player = $planet->getPlayer();
    $dearest = 0.0;

    foreach (ObjectService::getMilitaryShipObjects() as $hull) {
        if ($hull->properties->attack->calculate($player)->totalValue <= 1) {
            continue;
        }

        if (! ObjectService::objectRequirementsMet($hull->machine_name, $planet)) {
            continue;
        }

        if (ObjectService::getObjectMaxBuildAmount($hull->machine_name, $planet, true) < 1) {
            continue;
        }

        $dearest = max($dearest, ObjectService::getObjectPrice($hull->machine_name, $planet)->sum());
    }

    return $dearest;
}
