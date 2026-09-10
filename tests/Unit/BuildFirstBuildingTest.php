<?php

namespace Modules\AI\Tests\Unit;

use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiProfileSettings;
use Tests\TestCase;

class BuildFirstBuildingTest extends TestCase
{
    public function test_it_selects_the_highest_weighted_candidate_deterministically(): void
    {
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

        $decision = (new BuildFirstBuilding(new SeededBuildingScoringPolicy()))->choose($profile);

        $this->assertSame(FirstBuildingTarget::CrystalMine->value, $decision['building_id']);
        $this->assertSame('first_building:' . FirstBuildingTarget::CrystalMine->name, $decision['reason']);
    }
}
