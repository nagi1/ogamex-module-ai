<?php

use Modules\AI\Domain\Decision\DefenseComposition;
use Modules\AI\Domain\Decision\DefenseCompositionPlanner;
use Modules\AI\Domain\Decision\DefenseNeed;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Plenty of resources and the whole defence tech tree, so requirements never mask the ratio choice.
beforeEach(function (): void {
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('shipyard', 12);

    foreach (['laser_technology' => 12, 'energy_technology' => 6, 'ion_technology' => 4, 'weapon_technology' => 3, 'shielding_technology' => 2, 'plasma_technology' => 7] as $machineName => $level) {
        $this->playerSetResearchLevel($machineName, $level);
    }
});

// A balanced wall (100 light lasers anchor) at its ratio except Gauss Cannon: the one component the
// doctrine is behind on is chosen, even though Rocket Launcher is ahead of its own ratio.
test('a wall behind on Gauss Cannon but ahead on Rocket Launcher chooses Gauss Cannon', function (): void {
    defcompProfile($this->currentUserId, AiDefenseDoctrine::BalancedMixed);

    $this->planetAddUnit('light_laser', 100);
    $this->planetAddUnit('rocket_launcher', 40);
    $this->planetAddUnit('ion_cannon', 20);
    $this->planetAddUnit('heavy_laser', 10);
    $this->planetAddUnit('plasma_turret', 5);

    $composition = app(DefenseCompositionPlanner::class)->plan($this->planetService->getPlayer(), $this->planetService);

    expect($composition)->toBeInstanceOf(DefenseComposition::class)
        ->and($composition->unit->machine_name)->toBe('gauss_cannon')
        ->and($composition->doctrine)->toBe('balanced')
        ->and($composition->amount)->toBe(10);
});

// A wall already at its doctrine's ratio has nothing further to compose, whatever the planet holds.
test('a doctrine built to its own ratio chooses nothing further', function (): void {
    defcompProfile($this->currentUserId, AiDefenseDoctrine::BalancedMixed);

    $this->planetAddUnit('light_laser', 100);
    $this->planetAddUnit('rocket_launcher', 20);
    $this->planetAddUnit('ion_cannon', 20);
    $this->planetAddUnit('heavy_laser', 10);
    $this->planetAddUnit('gauss_cannon', 10);
    $this->planetAddUnit('plasma_turret', 5);

    expect(app(DefenseCompositionPlanner::class)->plan($this->planetService->getPlayer(), $this->planetService))->toBeNull();
});

// A doctrine that names a unit the host does not have is an error, not a silent fall back to some
// other unit: the file's names are the host's own.
test('an unknown unit name in a doctrine fails loudly', function (): void {
    defcompProfile($this->currentUserId, AiDefenseDoctrine::BalancedMixed);

    $path = (string) tempnam(sys_get_temp_dir(), 'ai-doctrines-');
    file_put_contents($path, <<<'YAML'
        doctrines:
          balanced:
            anchor: {unit: Light Laser, count: 100}
            ratio:
              Rocket Launcher: 20
              Plasma Cannon: 5
        YAML);

    try {
        expect(fn () => app()->makeWith(DefenseCompositionPlanner::class, ['doctrineFile' => $path])
            ->plan($this->planetService->getPlayer(), $this->planetService))
            ->toThrow(RuntimeException::class, 'no host defence object named "Plasma Cannon"');
    } finally {
        unlink($path);
    }
});

// The early-game doctrine hands over to the big-gun doctrine once the wall passes its stated anchor.
test('a wall past the stop rule hands over to the next doctrine', function (): void {
    defcompProfile($this->currentUserId, AiDefenseDoctrine::ProductionShell);

    $this->planetAddUnit('light_laser', 201);

    $composition = app(DefenseCompositionPlanner::class)->plan($this->planetService->getPlayer(), $this->planetService);

    expect($composition)->toBeInstanceOf(DefenseComposition::class)
        ->and($composition->doctrine)->toBe('big_gun_heavy')
        ->and($composition->unit->machine_name)->toBe('heavy_laser');
});

// A need is the size the wall is built to: the doctrine's own anchor is the floor an account with
// nothing at risk builds, and one that stands to lose more builds further out along the same ratio
// (PERS-004 states the value; pricing it against the ratio is the planner's business).
test('a need beyond the doctrine floor grows the wall the ratio is applied to', function (): void {
    defcompProfile($this->currentUserId, AiDefenseDoctrine::BalancedMixed);

    $this->planetAddUnit('light_laser', 100);

    $floor = app(DefenseCompositionPlanner::class)->plan($this->planetService->getPlayer(), $this->planetService);

    $need = app()->makeWith(DefenseNeed::class, [
        'defenceValue' => 20_000_000.0,
        'reason' => 'defense:need:inbound',
    ]);
    $grown = app(DefenseCompositionPlanner::class)->plan($this->planetService->getPlayer(), $this->planetService, $need);

    expect($floor)->toBeInstanceOf(DefenseComposition::class)
        ->and($floor->amount)->toBe(20)
        ->and($grown)->toBeInstanceOf(DefenseComposition::class)
        ->and($grown->unit->machine_name)->toBe($floor->unit->machine_name)
        ->and($grown->amount)->toBeGreaterThan($floor->amount);
});

// Below the stop rule the wall is still the early-game doctrine.
test('a wall inside the stop rule keeps its own doctrine', function (): void {
    defcompProfile($this->currentUserId, AiDefenseDoctrine::ProductionShell);

    $this->planetAddUnit('light_laser', 200);

    $composition = app(DefenseCompositionPlanner::class)->plan($this->planetService->getPlayer(), $this->planetService);

    expect($composition)->toBeInstanceOf(DefenseComposition::class)
        ->and($composition->doctrine)->toBe('early_game')
        ->and($composition->unit->machine_name)->toBe('rocket_launcher');
});

function defcompProfile(int $playerId, ?AiDefenseDoctrine $doctrine): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'defense_doctrine' => $doctrine,
        'random_seed' => 24_000 + $playerId,
        'enabled' => true,
    ]);
}
