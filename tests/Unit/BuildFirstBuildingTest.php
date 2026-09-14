<?php

use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Domain\Decision\BuildingScoringPolicy;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiProfileSettings;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(BuildingScoringPolicy::class, SeededBuildingScoringPolicy::class);
});

/** @param array<string, int> $weights */
function rankedProfile(int $playerId, array $weights = []): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
        'settings' => [AiProfileSettings::BUILDING_WEIGHTS => $weights],
    ]);
}

// The planner can only fall through a list it was given, so every economy target has to be on it:
// a target missing from the ranking is a target this account can never build.
test('it ranks every economy target exactly once, most wanted first, reproducibly', function (): void {
    $profile = rankedProfile($this->currentUserId + 500_000);

    $ranked = app(BuildFirstBuilding::class)->ranked($profile);
    $buildingIds = array_map(static fn ($candidate): int => $candidate->buildingId, $ranked);
    $reasons = array_map(static fn ($candidate): string => $candidate->reason, $ranked);

    expect($buildingIds)->toHaveCount(count(FirstBuildingTarget::cases()))
        ->and(array_unique($buildingIds))->toBe($buildingIds)
        ->and($reasons)->each->toStartWith('persona:')
        ->and($buildingIds)->toBe(array_map(
            static fn ($candidate): int => $candidate->buildingId,
            app(BuildFirstBuilding::class)->ranked($profile),
        ));
});

test('a persona preference puts its building at the front of the ranking', function (): void {
    $profile = rankedProfile($this->currentUserId + 500_001, [
        FirstBuildingTarget::CrystalMine->name => 100,
    ]);

    expect(app(BuildFirstBuilding::class)->ranked($profile)[0]->buildingId)
        ->toBe(FirstBuildingTarget::CrystalMine->value)
        ->and(app(BuildFirstBuilding::class)->ranked($profile)[0]->reason)
        ->toBe('persona:' . FirstBuildingTarget::CrystalMine->name);
});
