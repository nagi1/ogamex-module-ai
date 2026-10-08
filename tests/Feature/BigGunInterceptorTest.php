<?php

use Modules\AI\Domain\Decision\DefenseCompositionPlanner;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Only the early-game doctrine listed interceptors, so a wall past its stop rule never bought one and every late-game
// test missed the anti-ballistic missile. The later doctrines carry the wiki's two per plasma turret, capped by the silo.
test('a big-gun wall with everything else in place adds its interceptors', function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Turtle,
        'skill_band' => AiSkillBand::Standard,
        'defense_doctrine' => AiDefenseDoctrine::ProductionShell,
        'random_seed' => 31_000 + $this->currentUserId,
        'enabled' => true,
    ]);
    $this->planetAddResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    $this->planetSetObjectLevel('shipyard', 12);
    $this->planetSetObjectLevel('missile_silo', 4);
    foreach (['energy_technology' => 8, 'laser_technology' => 10, 'ion_technology' => 5, 'plasma_technology' => 7, 'weapon_technology' => 6,
        'shielding_technology' => 6, 'armor_technology' => 6, 'combustion_drive' => 6, 'impulse_drive' => 6] as $research => $level) {
        $this->playerSetResearchLevel($research, $level);
    }
    foreach (['rocket_launcher' => 1000, 'light_laser' => 500, 'heavy_laser' => 100, 'ion_cannon' => 40, 'gauss_cannon' => 20,
        'plasma_turret' => 10, 'small_shield_dome' => 1, 'large_shield_dome' => 1] as $unit => $amount) {
        $this->planetAddUnit($unit, $amount);
    }

    $wall = app(DefenseCompositionPlanner::class)->plan($this->planetService->getPlayer(), $this->planetService);

    expect($wall?->doctrine)->toBe('big_gun_heavy')
        ->and($wall?->unit->machine_name)->toBe('anti_ballistic_missile');
});

// A missile-firing account keeps half its silo for strikes: with interceptors already filling that half, it can order
// none, while an account that fires no missiles fills the whole silo (9401-9404: 100 interceptors, no missile).
test('interceptors fill half the silo of an account that fires missiles', function (AiArchetype $archetype, int $room): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 31_000 + $this->currentUserId,
        'enabled' => true,
    ]);
    $this->planetAddResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    $this->planetSetObjectLevel('missile_silo', 4);
    $this->planetAddUnit('anti_ballistic_missile', 20);
    $interceptor = ObjectService::getUnitObjectByMachineName('anti_ballistic_missile');

    // The planner's own affordability, the cap every standing wall order passes.
    $affordable = Closure::bind(fn ($planet, $unit): int => $this->affordable($planet, $unit), app(QueueableUnitPlanner::class), QueueableUnitPlanner::class);

    expect($affordable($this->planetService, $interceptor))->toBe($room);
})->with([
    'a turtle fires missiles' => [AiArchetype::Turtle, 0],
    'a miner fires none' => [AiArchetype::Miner, 20],
]);
