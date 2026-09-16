<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\CooperativeHostilityPolicy;
use OGame\Enums\UniverseMode;
use OGame\Services\HostilityGuard;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The cooperative policy only blocks human-on-human hostility: a player with an enabled
 * AI profile is the faction, so the coalition may fight it and it may fight back. The
 * registered-instance test proves the module's boot wiring puts the policy into the host
 * guard's hands without a caller.
 */
test('two coalition players are forbidden from fighting each other', function (): void {
    $foreignUserId = $this->createForeignPlanet()->getPlayer()?->getId();

    expect((new CooperativeHostilityPolicy())->forbids($this->currentUserId, $foreignUserId))->toBeTrue();
});

test('a coalition player may fight a faction account', function (): void {
    $foreignUserId = $this->createForeignPlanet()->getPlayer()?->getId();

    AiProfile::create([
        'player_id' => $foreignUserId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1,
        'enabled' => true,
    ]);

    expect((new CooperativeHostilityPolicy())->forbids($this->currentUserId, $foreignUserId))->toBeFalse();
});

test('a faction account may fight a coalition player', function (): void {
    $foreignUserId = $this->createForeignPlanet()->getPlayer()?->getId();

    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1,
        'enabled' => true,
    ]);

    expect((new CooperativeHostilityPolicy())->forbids($this->currentUserId, $foreignUserId))->toBeFalse();
});

test('the module registers its policy into the host guard', function (): void {
    app(SettingsService::class)->setUniverseMode(UniverseMode::Cooperative);
    $foreignUserId = $this->createForeignPlanet()->getPlayer()?->getId();

    expect(app(HostilityGuard::class)->forbids($this->currentUserId, $foreignUserId))->toBeTrue();
});
