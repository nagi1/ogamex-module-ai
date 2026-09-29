<?php

use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Resources;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('the unit planner sizes the cargo batch to the raid payload', function (): void {
    compositionProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('shipyard', 4);
    $this->playerSetResearchLevel('combustion_drive', 2);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('espionage_probe', 1);
    compositionRichReport($this->currentUserId);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toBe('role:cargo:payload')
        ->and($plan->amount)->toBeGreaterThan(1);
});

test('the unit planner does not grow cargo without a raidable report', function (): void {
    compositionProfile($this->currentUserId, AiArchetype::Fleeter);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('shipyard', 4);
    $this->playerSetResearchLevel('combustion_drive', 2);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('espionage_probe', 1);

    // No report: the opening roles are satisfied and nothing asks for more cargo. The planet's own
    // exposure is a different question and may ask for a standing wall (PERS-004).
    expect(app(QueueableUnitPlanner::class)->plan($this->currentUserId)?->reason)->not->toContain('role:cargo');
});

test('the standing wall goes to the planet that has none, not the one already walled', function (): void {
    compositionProfile($this->currentUserId);

    // Homeworld: mines that make it worth defending, the doctrine's own anchor already standing, and
    // the opening fleet so the roles before the wall are satisfied. Its need grows with its
    // production, so every pass still finds the wall thinner than what this planet stands to lose,
    // and it is the first planet the planner walks -- which is exactly the pressure that starved
    // every colony in the cohort this was found on.
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetSetObjectLevel('shipyard', 4);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetSetObjectLevel('metal_mine', 20);
    $this->planetSetObjectLevel('crystal_mine', 18);
    $this->planetSetObjectLevel('deuterium_synthesizer', 12);
    // Rich on purpose: a planet that already stands the anchor still wants a whole scaled layer next,
    // and a thin purse would drop it out of the running by accident. The accounts this defect was
    // found on hold billions, so the walled planet competes on every pass and must still lose.
    $this->planetAddResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('espionage_probe', 1);
    $this->planetAddUnit('light_laser', 60);

    // Colony: it mines just as hard and has never been given a single defence unit.
    $colony = $this->secondPlanetService;
    foreach (['robot_factory' => 2, 'shipyard' => 4, 'solar_plant' => 30, 'metal_mine' => 18, 'crystal_mine' => 16, 'deuterium_synthesizer' => 10] as $machineName => $level) {
        $colony->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, true);
    }
    $colony->addResources(new Resources(9_000_000, 9_000_000, 9_000_000));
    $colony->addUnit('small_cargo', 1);
    $colony->addUnit('colony_ship', 1);
    $colony->addUnit('espionage_probe', 1);

    // Levels written straight to the model leave the cached production and energy columns behind, and
    // the planner reads those from its own fresh service: without persisting the recomputed figures the
    // planets look unpowered, and a planet short of power has its production throttled to nothing.
    foreach ([$this->planetService, $colony] as $planet) {
        $planet->updateResourceProductionStats();
        $planet->updateResourceStorageStats();
    }

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan->reason)->toContain('role:defense:standing')
        ->and($plan->planetId)->toBe($colony->getPlanetId());
});

test('a report whose defence is null does not break unit planning', function (): void {
    compositionProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('shipyard', 4);
    $this->playerSetResearchLevel('combustion_drive', 2);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('espionage_probe', 1);

    // The host stores a probe that revealed no defence as a null `defense` column,
    // not an empty array; reading it as [] must not throw (the live grand run died
    // here on `foreach ($report->defense as ...)`).
    compositionRichReport($this->currentUserId, defense: null);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toBe('role:cargo:payload');
});

function compositionProfile(int $playerId, AiArchetype $archetype = AiArchetype::Miner): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 16_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A fresh report on a rich target, delivered through the account's own messages. */
function compositionRichReport(int $playerId, ?array $defense = []): void
{
    $foreign = test()->createForeignPlanet();
    $foreignPlayer = $foreign->getPlayer();
    expect($foreignPlayer)->not->toBeNull();

    $report = new EspionageReport;
    $report->planet_galaxy = $foreign->getPlanetCoordinates()->galaxy;
    $report->planet_system = $foreign->getPlanetCoordinates()->system;
    $report->planet_position = $foreign->getPlanetCoordinates()->position;
    $report->planet_type = (int) $foreign->getPlanetType()->value;
    $report->planet_user_id = $foreignPlayer->getId();
    $report->resources = ['metal' => 100_000, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = $defense;
    $report->player_info = ['player_name' => 'Rich', 'player_status' => 'inactive'];
    $report->save();

    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);
}
