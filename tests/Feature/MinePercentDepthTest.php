<?php

use Modules\AI\Actions\QueueAiMinePercentAction;
use Modules\AI\Contracts\QueueAiMinePercent;
use Modules\AI\Domain\Decision\QueueableMinePercent;
use Modules\AI\Domain\Decision\QueueableMinePercentPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiMinePercent::class, QueueAiMinePercentAction::class);
});

function minePercentProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 13_000 + $playerId,
        'enabled' => true,
    ]);
}

// A planet in deficit throttles the mine the host says yields the least output per
// energy, to a percentage below full — the lever no human leaves untouched.
test('a deficit throttles a mine below full percentage', function (): void {
    minePercentProfile($this->currentUserId);
    // Mines far outdraw the unbuilt plant: no solar plant, level-20 mines.
    $this->planetSetObjectLevel('metal_mine', 20);
    $this->planetSetObjectLevel('crystal_mine', 20);

    $plan = app(QueueableMinePercentPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableMinePercent::class)
        ->and($plan->percentage)->toBeLessThan(10)
        ->and($plan->reason)->toStartWith('throttle:');
});

// Once the power covers the mines again, a throttled mine goes back to full — and
// only when the restored draw still fits, so the two never oscillate.
test('a surplus restores a throttled mine to full', function (): void {
    minePercentProfile($this->currentUserId);
    $this->planetSetObjectLevel('metal_mine', 10);
    $this->planetSetObjectLevel('solar_plant', 30);
    $this->planetService->setBuildingPercent(ObjectService::getObjectByMachineName('metal_mine')->id, 5);

    $plan = app(QueueableMinePercentPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableMinePercent::class)
        ->and($plan->percentage)->toBe(10)
        ->and($plan->reason)->toStartWith('restore:');
});
