<?php

use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// QUAL-003: the bare planet beside the wall takes the host's cheapest defence when the doctrine names
// nothing its yard can build. The order is only as big as the balance it was priced against -- the host
// takes the whole price when the order is placed, so a planet holding less than one unit's worth has to
// wait for a later login instead of being sent a batch the host refuses.
test('a bare colony holding exactly one unit\'s price takes one wall unit', function (): void {
    bareAffordProfile($this->currentUserId);

    // Homeworld: a wall standing, so the account is past its opening, and a yard high enough that the
    // only planet that can be bare is the colony.
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 4);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetAddResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
    $this->planetAddUnit('light_laser', 60);
    bareAffordHomeFleet($this->planetService);

    $price = ObjectService::getObjectRawPrice('rocket_launcher');
    $colony = bareAffordColony($this->secondPlanetService, (int) $price->metal->get());

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan->planetId)->toBe($colony->getPlanetId())
        ->and($plan->reason)->toContain('role:defense:standing')
        ->and(ObjectService::getObjectById($plan->unitId)->machine_name)->toBe('rocket_launcher')
        ->and($plan->amount)->toBe(1);
});

// The zero bound: a planet that cannot pay for a single unit is left to a later login, because the host
// refuses an order it cannot pay for whole. A unit ordered at zero amount is a work item that fails, not
// a wall.
test('a bare colony holding less than one unit\'s price is not sent a wall at all', function (): void {
    bareAffordProfile($this->currentUserId);

    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 4);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetAddResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
    $this->planetAddUnit('light_laser', 60);
    bareAffordHomeFleet($this->planetService);

    $price = ObjectService::getObjectRawPrice('rocket_launcher');
    $colony = bareAffordColony($this->secondPlanetService, (int) $price->metal->get() - 1);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    $orderedOnColony = $plan !== null
        && $plan->planetId === $colony->getPlanetId()
        && str_contains($plan->reason, 'role:defense');

    expect($orderedOnColony)->toBeFalse('expected no unaffordable wall order on the bare colony, but got ' . ($plan->reason ?? 'nothing')
        . ' amount=' . ($plan->amount ?? '-') . ' metal=' . $colony->metal()->get()
        . ' price=' . $price->metal->get() . ' affordable=' . ObjectService::getObjectMaxBuildAmount('rocket_launcher', $colony, true));
});

/** A miner whose doctrine starts on a unit a level-one yard cannot build, so the wall is sized from the host's price. */
function bareAffordProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'defense_doctrine' => AiDefenseDoctrine::RocketPlasma,
        'random_seed' => 44_000 + $playerId,
        'enabled' => true,
    ]);
}

/**
 * The bare colony beside the wall: no mines, so its balance is exactly what it is handed, a yard at level
 * one whose only buildable defence is the host's Rocket Launcher, and never a defence unit.
 */
function bareAffordColony(?PlanetService $colony, int $metal): PlanetService
{
    $colony = $colony ?? throw new LogicException('the account must own a colony for this story.');

    foreach (['metal_mine', 'crystal_mine', 'deuterium_synthesizer'] as $machineName) {
        $colony->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, 0, true);
    }

    $colony->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 30, true);
    $colony->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 1, true);
    $colony->updateResourceProductionStats();
    $colony->updateResourceStorageStats();

    // The host hands a fresh planet a starting balance that is only settled on the next resources read, so
    // the balance is settled here and then set exactly: the story is about a planet whose whole holding is
    // one unit's price, not about whatever the host happened to put there first.
    $colony->updateResources();
    $held = (int) $colony->metal()->get();
    if ($metal > $held) {
        $colony->addResources(new Resources($metal - $held, 0, 0));
    }
    if ($metal < $held) {
        $colony->deductResources(new Resources($held - $metal, 0, 0));
    }

    return $colony;
}

/** The homeworld's opening roles satisfied, so the planner is left with the wall and nothing else. */
function bareAffordHomeFleet(PlanetService $planet): void
{
    $planet->addUnit('small_cargo', 1);
    $planet->addUnit('colony_ship', 1);
    $planet->addUnit('espionage_probe', 1);
}
