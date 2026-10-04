<?php

use Modules\AI\Domain\Decision\EconomyUpgrades;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Models\AiProfile;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// COVER_OBJECTS: no account in the cohort owned a nano factory, because the station project had its turn
// only on a planet whose mines had stopped paying -- and on the cohort's 1000x economy a mine that repays
// within the hour always costs less than the station behind it, so the mines never stopped paying and the
// station was never offered. It has its turn now once the planet's mines have outgrown it: every upgrade
// that still repays is a dearer purchase than the cheapest station the catalogue offers the planet.
//
// The stories run at the cohort's own economy speed, because at speed 1 a mine stops paying long before
// its price reaches a station's: the branch under test would never be reached there, which is exactly how
// the station project came to look green in a test and absent on the cohort.

/** The cohort's own universe speed: what decides whether a mine still repays inside the payback horizon. */
function outgrownStationFastUniverse(): void
{
    app(SettingsService::class)->set('economy_speed', 1000);
}

/**
 * A settled planet that holds every station but the nano factory, with the wall a login would otherwise
 * spend its yard on already standing: deep mines whose next level costs millions, the factory and the
 * technology the nano factory waits on already built, and stock enough to pay for it.
 */
function outgrownStationPlanet(Situation $situation): Situation
{
    return $situation
        ->resources(20_000_000, 10_000_000, 2_000_000)
        ->level('metal_mine', 28)
        ->level('crystal_mine', 28)
        ->level('deuterium_synthesizer', 28)
        ->level('solar_plant', 40)
        ->level('metal_store', 20)
        ->level('crystal_store', 20)
        ->level('deuterium_store', 20)
        ->level('terraformer', 25)
        ->level('shipyard', 2)
        ->level('research_lab', 11)
        ->level('alliance_depot', 1)
        ->level('missile_silo', 1)
        ->level('space_dock', 1)
        ->level('robot_factory', 10)
        ->research('computer_technology', 10)
        ->defence('rocket_launcher', 300);
}

/** The planet, refreshed the way a login reads it before the planner is asked. */
function outgrownStationRead(int $playerId): PlanetService
{
    $planet = array_values(app(PlayerServiceFactory::class)->make($playerId, true)->planets->all())[0];
    $planet->updateResources(false);
    $planet->updateResourceProductionStats(false);
    $planet->updateResourceStorageStats(false);

    return $planet;
}

/** Every step the planet's routine pass offers, in the planner's own order, so a failure names them. */
function outgrownStationSteps(PlanetService $planet): string
{
    $profile = AiProfile::query()->where('player_id', $planet->getPlayer()?->getId())->firstOrFail();

    return implode(', ', array_map(
        static fn ($candidate): string => $candidate->reason,
        app(QueueableBuildingPlanner::class)->passes($profile)['routine']($planet),
    ));
}

test('a planet whose mines have outgrown its stations takes the station project', function (): void {
    outgrownStationFastUniverse();
    outgrownStationPlanet(Situation::of($this));
    $planet = outgrownStationRead($this->currentUserId);

    $routine = outgrownStationSteps($planet);
    expect(str_contains($routine, 'station:nano_factory'))->toBeTrue('expected the station project, but the planet offered: ' . $routine);

    // The story only means something while the mines still repay: the station is offered because this
    // planet's own mine upgrades have grown past it, not because the economy ran out of mine steps.
    $profile = AiProfile::query()->where('player_id', $this->currentUserId)->firstOrFail();
    expect(app(EconomyUpgrades::class)->production($planet, $profile))->not->toBeEmpty();
});

test('the login queues the nano factory of a planet the mines have outgrown', function (): void {
    outgrownStationFastUniverse();
    $situation = outgrownStationPlanet(Situation::of($this))->session();

    $situation->expectQueued('nano_factory');
});

test('a planet whose mine is still the small purchase keeps mining', function (): void {
    outgrownStationFastUniverse();
    $situation = outgrownStationPlanet(Situation::of($this))->level('metal_mine', 8);
    $planet = outgrownStationRead($this->currentUserId);

    $routine = outgrownStationSteps($planet);
    expect(str_contains($routine, 'station:'))->toBeFalse('expected the cheap mine to keep the build slot, but the planet offered: ' . $routine)
        ->and(str_contains($routine, 'economy:metal_mine'))->toBeTrue('expected the mine to stay the step, but the planet offered: ' . $routine);

    $situation->session();
    expect(in_array('nano_factory', $situation->queued(), true))->toBeFalse(
        'expected the cheap mine to keep the build slot, but ' . $situation->account(),
    );
});
