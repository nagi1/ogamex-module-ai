<?php

use Modules\AI\Domain\Decision\QueueableBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// A planet down to its last fields buys the next level of what adds fields; the station project only ever asked for the
// first level, so a planet that filled up afterwards never built again. The raiser is found by the host's field formula.

test('a planet down to its last fields builds the next level of what adds fields', function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 11,
        'enabled' => true,
    ]);

    $raiser = null;
    foreach (ObjectService::getStationObjects() as $station) {
        $before = $this->planetService->getPlanetFieldMax();
        $this->planetService->setObjectLevel($station->id, 1, false);
        $raises = $this->planetService->getPlanetFieldMax() > $before && ObjectService::objectValidPlanetType($station->machine_name, $this->planetService);
        $this->planetService->setObjectLevel($station->id, 0, false);
        $raiser ??= $raises ? $station : null;
    }
    expect($raiser)->not->toBeNull();

    foreach (ObjectService::getRecursiveRequirements($raiser->machine_name) as $machineName => $level) {
        ObjectService::getObjectByMachineName($machineName)->type === GameObjectType::Research
            ? $this->playerSetResearchLevel($machineName, $level)
            : $this->planetSetObjectLevel($machineName, $level);
    }
    // Every station the planet can hold already stands, so the station project has nothing left to ask for.
    foreach (ObjectService::getStationObjects() as $station) {
        if (ObjectService::objectValidPlanetType($station->machine_name, $this->planetService) && $this->planetService->getObjectLevel($station->machine_name) === 0) {
            $this->planetSetObjectLevel($station->machine_name, 1);
        }
    }
    // The rest of the fields go to buildings a few levels at a time, until one is left.
    // Buildings that add fields grow the free count as they fill, so one pass can stop early: repeat until one is left.
    while ($this->planetService->getPlanetFieldMax() - $this->planetService->getBuildingCount() > 1) {
        foreach (ObjectService::getBuildingObjects() as $building) {
            $free = $this->planetService->getPlanetFieldMax() - $this->planetService->getBuildingCount();
            if ($building->consumesPlanetField && $free > 1) {
                $this->planetSetObjectLevel($building->machine_name, $this->planetService->getObjectLevel($building->machine_name) + min(20, $free - 1));
            }
        }
    }
    expect($this->planetService->getPlanetFieldMax() - $this->planetService->getBuildingCount())->toBe(1);
    // The terraformer is priced in energy, which a planet with no fields to spare makes from satellites.
    $this->planetAddUnit('solar_satellite', 2000);
    $this->planetAddResources(new Resources(1e9, 1e9, 1e9));

    $buildings = array_values(array_filter(
        app(QueueableBuildingPlanner::class)->steps($this->currentUserId),
        static fn ($step): bool => $step instanceof QueueableBuilding,
    ));

    expect($buildings)->not->toBeEmpty()
        ->and($buildings[0]->buildingId)->toBe($raiser->id);
});
