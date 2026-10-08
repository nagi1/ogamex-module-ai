<?php

use Modules\AI\Domain\Decision\DefenseCompositionPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The host lets a planet hold one of a dome and asks that of built units only: a dome already in the yard is not offered
// again, or the wall order goes to a refusal on every login.
test('a one-per-planet unit already in the yard is not ordered again', function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Turtle,
        'skill_band' => AiSkillBand::Standard,
        'defense_doctrine' => AiDefenseDoctrine::ProductionShell,
        'random_seed' => 32_000 + $this->currentUserId,
        'enabled' => true,
    ]);
    $this->planetAddResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    $this->planetSetObjectLevel('shipyard', 12);
    foreach (['energy_technology' => 8, 'laser_technology' => 10, 'ion_technology' => 5, 'plasma_technology' => 7, 'weapon_technology' => 6,
        'shielding_technology' => 6, 'armor_technology' => 6, 'combustion_drive' => 6, 'impulse_drive' => 6] as $research => $level) {
        $this->playerSetResearchLevel($research, $level);
    }
    // A wall three doctrine layers deep, so the ratio asks for three of each dome; everything else is held, and the domes
    // the host allows (one each) are already paid for in the yard.
    foreach (['rocket_launcher' => 3000, 'light_laser' => 1500, 'heavy_laser' => 300, 'ion_cannon' => 120, 'gauss_cannon' => 60, 'plasma_turret' => 30] as $unit => $amount) {
        $this->planetAddUnit($unit, $amount);
    }
    foreach (['small_shield_dome', 'large_shield_dome'] as $dome) {
        OGame\Models\UnitQueue::query()->forceCreate([
            'planet_id' => $this->currentPlanetId, 'object_id' => ObjectService::getUnitObjectByMachineName($dome)->id, 'object_amount' => 1,
            'time_duration' => 3600, 'time_start' => now()->timestamp, 'time_end' => now()->addHour()->timestamp,
            'time_progress' => 0, 'object_amount_progress' => 0, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'processed' => 0,
        ]);
    }

    $wall = app(DefenseCompositionPlanner::class)->plan($this->planetService->getPlayer(), $this->planetService);

    expect($wall?->unit->machine_name)->not->toBeIn(['small_shield_dome', 'large_shield_dome']);
});

test('the archetype wall template does not offer a dome already in the yard', function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Turtle,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 33_000 + $this->currentUserId,
        'enabled' => true,
    ]);
    $this->planetAddResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    $this->planetSetObjectLevel('shipyard', 12);
    $this->playerSetResearchLevel('shielding_technology', 6);
    $this->planetAddUnit('rocket_launcher', 10);
    OGame\Models\UnitQueue::query()->forceCreate([
        'planet_id' => $this->currentPlanetId, 'object_id' => ObjectService::getUnitObjectByMachineName('small_shield_dome')->id, 'object_amount' => 1,
        'time_duration' => 3600, 'time_start' => now()->timestamp, 'time_end' => now()->addHour()->timestamp,
        'time_progress' => 0, 'object_amount_progress' => 0, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'processed' => 0,
    ]);

    $planner = app(Modules\AI\Domain\Decision\QueueableUnitPlanner::class);
    $unit = (new ReflectionMethod($planner, 'templateDefence'))->invoke($planner, $this->planetService);

    expect($unit?->machine_name)->not->toBe('small_shield_dome');
});
