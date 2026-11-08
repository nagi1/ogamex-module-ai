<?php

use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Actions\QueueAiResearchAction;
use Modules\AI\Enums\AiQueueActionReason;
use OGame\Models\BuildingQueue;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The host takes a row the planet cannot pay for and cancels it the moment it starts: in three hours of the 1000x
// universe 1,417 of 1,430 cancelled building rows and 1,115 of 1,788 research rows were such orders, the stock spent
// between the decision and the order (8 Nov 2026). The adapter refuses them instead of writing a row the host cancels.

test('a building the planet cannot pay for now is refused, not queued and cancelled', function (): void {
    $this->planetDeductResources($this->planetService->getResources());
    $building = ObjectService::getObjectByMachineName('metal_mine');

    $result = app(QueueAiBuildingAction::class)->handle($this->currentUserId, $this->currentPlanetId, $building->id);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::CannotAffordAtQueue->value)
        ->and(BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->where('object_id', $building->id)->exists())->toBeFalse();

    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));

    expect(app(QueueAiBuildingAction::class)->handle($this->currentUserId, $this->currentPlanetId, $building->id)->successful)->toBeTrue();
});

test('a research the planet cannot pay for now is refused, not queued and cancelled', function (): void {
    $this->planetSetObjectLevel('research_lab', 1);
    $this->planetDeductResources($this->planetService->getResources());
    $research = ObjectService::getObjectByMachineName('energy_technology');

    $result = app(QueueAiResearchAction::class)->handle($this->currentUserId, $this->currentPlanetId, $research->id);

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::CannotAffordAtQueue->value)
        ->and(ResearchQueue::query()->where('planet_id', $this->currentPlanetId)->where('object_id', $research->id)->exists())->toBeFalse();

    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));

    expect(app(QueueAiResearchAction::class)->handle($this->currentUserId, $this->currentPlanetId, $research->id)->successful)->toBeTrue();
});
