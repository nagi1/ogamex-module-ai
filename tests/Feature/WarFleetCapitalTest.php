<?php

use Modules\AI\Domain\Decision\FacilityChain;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function warFleetProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7,
        'enabled' => true,
    ]);
}

// LIFE-002: the war fleet the host's own catalogue prices is what the account raises its yard and
// research toward, so large battles and, once research allows, death stars appear without a named hull.
test('the chain asks for the facilities a war-fleet hull needs', function (): void {
    warFleetProfile($this->currentUserId);
    $this->planetAddResources(new Resources(50_000_000, 30_000_000, 20_000_000));
    // The account is past its opening: a hull that can fly already exists, so the chain's own goal is
    // the war fleet rather than the first cargo ship.
    $this->planetAddUnit('large_cargo', 1);

    $planet = app(PlanetServiceFactory::class)->make($this->currentPlanetId, true);
    $steps = app(FacilityChain::class)->pending($planet);
    $names = array_map(static fn ($step): string => ObjectService::getObjectById($step->buildingId)->machine_name, $steps);

    $dearest = null;
    $dearestPrice = 0.0;
    foreach (ObjectService::getMilitaryShipObjects() as $hull) {
        $price = (float) $hull->price->resources->sum();
        if ($price > $dearestPrice) {
            $dearest = $hull->machine_name;
            $dearestPrice = $price;
        }
    }

    $needed = array_keys(ObjectService::getRecursiveRequirements($dearest));
    expect($names)->not->toBeEmpty()
        ->and(array_intersect($names, $needed))->not->toBeEmpty();
});
