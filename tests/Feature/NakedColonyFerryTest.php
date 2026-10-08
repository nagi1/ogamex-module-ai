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

// A moon makes nothing, so the last few hundred it is short for its lunar base never arrive on their own: the ferry
// ships them however far below the ordinary floor (a 98%-stocked moon sat unbuilt for the whole of a late-game test).
test('a moon a few hundred short of its lunar base is ferried the difference', function (): void {
    app()->bind(QueueAiTransfer::class, QueueAiTransferAction::class);
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 9_100 + $this->currentUserId,
        'enabled' => true,
    ]);

    $moon = app(OGame\Factories\PlanetServiceFactory::class)->createMoonForPlanet($this->planetService, 2_000_000, 20, 15);
    $moon->addResources(new Resources(19_600, 39_880, 19_801));
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('large_cargo', 8);

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableTransfer::class)
        ->and($plan?->targetPlanetId)->toBe($moon->getPlanetId())
        ->and($plan?->metal + $plan?->crystal)->toBeLessThan(QueueableTransferPlanner::MINIMUM_SHIPMENT)
        // The planner keeps a tenth of a balance after a purchase and a moon cannot refill it, so the price alone is not enough.
        ->and(19_600 + $plan?->metal)->toBeGreaterThanOrEqual(20_000 / 0.9 - 1);
});

// A moon step no planet can pay at once is gathered in trips: the source sends what it spares above its reserve.
test('a moon step dearer than any one planet holds is ferried in instalments', function (): void {
    app()->bind(QueueAiTransfer::class, QueueAiTransferAction::class);
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 9_200 + $this->currentUserId,
        'enabled' => true,
    ]);

    $moon = app(OGame\Factories\PlanetServiceFactory::class)->createMoonForPlanet($this->planetService, 2_000_000, 20, 15);
    $moon->setObjectLevel(OGame\Services\ObjectService::getObjectByMachineName('lunar_base')->id, 1, true);
    $moon->setObjectLevel(OGame\Services\ObjectService::getObjectByMachineName('sensor_phalanx')->id, 1, true);
    // A factory that no longer pays back on the gate, so the gate itself is the step.
    $moon->setObjectLevel(OGame\Services\ObjectService::getObjectByMachineName('lunar_base')->id, 8, true);
    $moon->setObjectLevel(OGame\Services\ObjectService::getObjectByMachineName('robot_factory')->id, 14, true);
    $this->playerSetResearchLevel('hyperspace_technology', 7);
    // The gate is a step only for an account with a second moon to jump to.
    app(OGame\Factories\PlanetServiceFactory::class)->createMoonForPlanet($this->secondPlanetService, 2_000_000, 20, 15);
    $this->planetAddResources(new Resources(3_000_000, 3_000_000, 3_000_000));
    $this->planetAddUnit('large_cargo', 400);

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableTransfer::class)
        ->and($plan?->targetPlanetId)->toBe($moon->getPlanetId())
        ->and($plan?->crystal)->toBeGreaterThan(1_000_000)
        ->and($plan?->crystal)->toBeLessThanOrEqual(3_000_000 + $this->planetService->crystal()->get());
});

// A moon has no storage, so the surplus sweep read the gate's instalments as overflow and flew them back out (9352).
test('a moon saving for its jump gate is not swept back to the planets', function (): void {
    app()->bind(QueueAiTransfer::class, QueueAiTransferAction::class);
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 9_300 + $this->currentUserId,
        'enabled' => true,
    ]);

    $moon = app(OGame\Factories\PlanetServiceFactory::class)->createMoonForPlanet($this->planetService, 2_000_000, 20, 15);
    $moon->setObjectLevel(OGame\Services\ObjectService::getObjectByMachineName('lunar_base')->id, 1, true);
    $moon->setObjectLevel(OGame\Services\ObjectService::getObjectByMachineName('sensor_phalanx')->id, 1, true);
    $this->playerSetResearchLevel('hyperspace_technology', 7);
    app(OGame\Factories\PlanetServiceFactory::class)->createMoonForPlanet($this->secondPlanetService, 2_000_000, 20, 15);
    $moon->addResources(new Resources(1_500_000, 3_000_000, 1_000_000));
    $moon->addUnit('large_cargo', 400);

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);

    expect($plan?->sourcePlanetId)->not->toBe($moon->getPlanetId());
});

// Behind every planet's routine step a moon waited up to seventeen hours for its first shipment (9373).
test('a bare moon is fed before a colony short of its wall facilities', function (): void {
    app()->bind(QueueAiTransfer::class, QueueAiTransferAction::class);
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 9_400 + $this->currentUserId,
        'enabled' => true,
    ]);

    $moon = app(OGame\Factories\PlanetServiceFactory::class)->createMoonForPlanet($this->secondPlanetService, 2_000_000, 20, 15);
    $this->planetAddResources(new Resources(3_000_000, 3_000_000, 3_000_000));
    $this->planetAddUnit('large_cargo', 400);
    // A bare colony beside a walled homeworld is owed its wall's facilities: a planet with a shipment of its own due.
    $this->planetAddUnit('rocket_launcher', 10);

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);

    expect($plan?->targetPlanetId)->toBe($moon->getPlanetId());
});
