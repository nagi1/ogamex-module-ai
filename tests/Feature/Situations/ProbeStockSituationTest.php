<?php

use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Models\AiProfile;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\FleetMission;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// War world (WORK-ORDER §2, PHP 3): the probe stock a raider keeps is the idle one. Probes out on a volley are not on
// the shelf, so the yard orders them back; and a probe order is not held behind the building the planet saves for,
// or a late account saving for a dear building stops scouting for good.

/** A settled raider with a shipyard, a fleet, and neighbours to scout. */
function probeStockRaider(object $test): Situation
{
    return Situation::of($test)
        ->archetype(AiArchetype::Raider)
        ->level('shipyard', 4)
        ->research('espionage_technology', 2)
        ->research('combustion_drive', 3)
        ->ships('small_cargo', 1)
        ->colony();
}

/** $amount of the account's probes out on an espionage flight from its home planet. */
function probeStockFlying(object $test, int $amount): void
{
    $home = (fn () => $this->planetService)->call($test);
    $foreign = (fn () => $this->createForeignPlanet())->call($test);
    $at = $foreign->getPlanetCoordinates();
    $mission = new FleetMission();
    $mission->user_id = $home->getPlayer()->getId();
    $mission->planet_id_from = $home->getPlanetId();
    $mission->planet_id_to = $foreign->getPlanetId();
    $mission->galaxy_to = $at->galaxy;
    $mission->system_to = $at->system;
    $mission->position_to = $at->position;
    $mission->type_to = 1;
    $mission->mission_type = 6;
    $mission->espionage_probe = $amount;
    $mission->time_departure = now()->timestamp;
    $mission->time_arrival = now()->addMinutes(20)->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();
}

function probeStockPlan(int $playerId): QueueableUnit|null
{
    return app(QueueableUnitPlanner::class)->plan($playerId);
}

test('a raider with its probe stock on the shelf orders no probe', function (): void {
    probeStockRaider($this)->resources(1_000_000, 1_000_000, 1_000_000)->ships('espionage_probe', 15);

    expect(probeStockPlan($this->currentUserId)?->reason)->not->toBe('role:probe');
});

test('a raider whose probes are all out on a volley orders the stock back', function (): void {
    probeStockRaider($this)->resources(1_000_000, 1_000_000, 1_000_000);
    probeStockFlying($this, 15);

    $plan = probeStockPlan($this->currentUserId);

    expect($plan?->reason)->toBe('role:probe')
        ->and($plan?->amount)->toBe(15);
});

test('probes out on a volley are not sent again until they are home', function (): void {
    probeStockRaider($this)->resources(1_000_000, 1_000_000, 1_000_000)->crowd(3);
    probeStockFlying($this, 15);

    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId))->toBeNull();

    $this->planetAddUnit('espionage_probe', 15);

    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId))->not->toBeNull();
});

test('a raider saving for a building dearer than its stock still orders probes', function (): void {
    probeStockRaider($this)
        ->levelEveryPlanet('metal_mine', 12)
        ->levelEveryPlanet('crystal_mine', 10)
        ->levelEveryPlanet('solar_plant', 20)
        ->resources(2_000, 20_000, 2_000);
    $home = app(PlanetServiceFactory::class)->make($this->currentPlanetId, true);
    // The planted levels' power, so the yard's first need is the probes and not a power gap the planting left.
    $home->updateResourceProductionStats();
    $saving = app(QueueableBuildingPlanner::class)->savingFor($home, AiProfile::query()->where('player_id', $this->currentUserId)->firstOrFail());

    $plan = probeStockPlan($this->currentUserId);

    expect($saving)->not->toBeNull('the planet saves for a building')
        ->and($plan?->reason)->toBe('role:probe');
});

// No cheating (rule 10): a rival's shelf and flights are not the account's to see, so its probe order stays the same.
test('a rival\'s probes, unseen, leave the raider\'s probe order unchanged', function (): void {
    probeStockRaider($this)->resources(1_000_000, 1_000_000, 1_000_000);
    $before = probeStockPlan($this->currentUserId);
    $rival = $this->createForeignPlanet();
    $rival->addUnit('espionage_probe', 500);
    $rival->addUnit('light_fighter', 500);

    $after = probeStockPlan($this->currentUserId);

    expect([$after?->reason, $after?->amount])->toBe([$before?->reason, $before?->amount])
        ->and($after?->reason)->toBe('role:probe');
});
