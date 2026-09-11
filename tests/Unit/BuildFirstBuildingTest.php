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

test('it selects the highest weighted candidate deterministically', function () {
    $profile = new AiProfile([
        'id' => 1,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'settings' => [
            AiProfileSettings::BUILDING_WEIGHTS => [
                FirstBuildingTarget::CrystalMine->name => 100,
            ],
        ],
    ]);

    $decision = app(BuildFirstBuilding::class)->choose($profile);

    expect($decision['building_id'])->toBe(FirstBuildingTarget::CrystalMine->value);
    expect($decision['reason'])->toBe('first_building:' . FirstBuildingTarget::CrystalMine->name);
});
