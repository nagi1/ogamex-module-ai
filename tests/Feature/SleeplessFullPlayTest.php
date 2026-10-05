<?php

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// `ai:sim --full-play` is for generating training data: accounts must not wait out the night, or a short
// run never reaches the late game. The flag lives in config and is false everywhere else.

function sleeplessProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 20_011,
        'enabled' => true,
    ]);
}

function sleeplessNightInstant(AiProfile $profile): CarbonImmutable
{
    $planner = app(SessionPlanner::class);
    $instant = now()->toImmutable()->startOfDay();

    for ($step = 0; $step < 2880 && $planner->isAwake($profile, $instant); $step++) {
        $instant = $instant->addMinutes(10);
    }

    return $instant;
}

test('an account sleeps through its night unless the run is full play', function (): void {
    $profile = sleeplessProfile($this->currentUserId);
    $night = sleeplessNightInstant($profile);
    $planner = app(SessionPlanner::class);

    expect($planner->isAwake($profile, $night))->toBeFalse();

    config(['ai.population.sleepless' => true]);

    expect($planner->isAwake($profile, $night))->toBeTrue();
});
