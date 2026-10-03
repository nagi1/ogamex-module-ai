<?php

use Modules\AI\Actions\QueueAiTransferAction;
use Modules\AI\Contracts\QueueAiTransfer;
use Modules\AI\Domain\Decision\QueueableTransfer;
use Modules\AI\Domain\Decision\QueueableTransferPlanner;
use Modules\AI\Models\AiProfile;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// PERS-005 (invariant:NAKED_BESIDE_WALLED): a colony short of a few hundred deuterium for the yard its
// wall waits on is ferried the difference, however far below the ordinary shipment floor it is.
test('a bare colony beside a walled homeworld is ferried the deuterium its wall prerequisite lacks', function (): void {
    app()->bind(QueueAiTransfer::class, QueueAiTransferAction::class);
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 9_000 + $this->currentUserId,
        'enabled' => true,
    ]);

    $colony = $this->secondPlanetService;
    $colony->deductResources(new Resources($colony->metal()->get(), $colony->crystal()->get(), $colony->deuterium()->get()));
    $colony->addResources(new Resources(20_000, 20_000, 10));

    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('large_cargo', 8);
    $this->planetAddUnit('rocket_launcher', 50);

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableTransfer::class)
        ->and($plan?->targetPlanetId)->toBe($colony->getPlanetId())
        ->and($plan?->deuterium)->toBeGreaterThan(0)
        ->and($plan?->metal + $plan?->crystal)->toBeLessThan(QueueableTransferPlanner::MINIMUM_SHIPMENT);
});
