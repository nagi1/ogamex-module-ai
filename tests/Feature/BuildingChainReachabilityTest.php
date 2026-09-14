<?php

use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\BuildingQueue;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiBuilding::class, QueueAiBuildingAction::class);
});

/**
 * A capability the account can never reach is not a capability, so this suite follows the account
 * rather than the plan: each round it plans the next building, queues it through the real host queue,
 * lets the host finish it, and then reads what the account actually owns.
 *
 * Every expectation below is computed from the host's own catalogue, which is also the proof that the
 * module keeps no list of its own: a planning rule that named its own buildings would satisfy itself
 * here and drift the moment the catalogue changed.
 */
test('a funded account reaches a research lab, a robotics factory and a shipyard', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());

    $steps = [];
    foreach (range(1, 12) as $round) {
        $steps[] = chainBuildOnce($this->currentUserId, $this->currentPlanetId);
    }

    // The assertion is reachability, not a script. Every building the account queued was either
    // capacity, because the host throttles a planet that cannot cover its mines, or a prerequisite the
    // host's own requirement graph names -- nothing the module decided for itself.
    $chainSteps = array_values(array_filter($steps, static fn (string $step): bool => str_starts_with($step, 'chain:')));

    expect($chainSteps)->not->toBeEmpty();
    foreach ($chainSteps as $step) {
        expect(substr($step, strlen('chain:')))->toBeIn(array_keys(chainHostPrerequisites()));
    }

    $planet = chainPlanet($this->currentUserId, $this->currentPlanetId);

    expect($planet->getObjectLevel('research_lab'))->toBeGreaterThanOrEqual(1)
        ->and($planet->getObjectLevel('robot_factory'))->toBeGreaterThanOrEqual(2)
        ->and($planet->getObjectLevel('shipyard'))->toBeGreaterThanOrEqual(1);
});

// The chain is derived, not declared: the building the planner asks for is one the host's own
// requirement graph lists for something this account could produce, so a module that adds a ship or a
// technology extends the plan without a line of module policy changing.
test('the chain asks for a prerequisite the host names', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());
    chainPowered();

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);
    $machineName = ObjectService::getObjectById((int) $plan?->buildingId)->machine_name;

    expect(chainHostPrerequisites())->toHaveKey($machineName)
        ->and($plan?->reason)->toBe('chain:' . $machineName);
});

// The opening step is the easiest unlock and not the most impressive one. Ordering by the level the
// host asks for is what keeps a fresh account from climbing towards a level twelve shipyard it cannot
// use, which is the plan an ambition's own price as the ordering produced.
test('the first step is the easiest unlock the host asks for', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());
    chainPowered();

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);
    $machineName = ObjectService::getObjectById((int) $plan?->buildingId)->machine_name;

    expect(chainHostPrerequisites()[$machineName]['easiest'])->toBe(1);
});

// The chain is bounded by the host's graph rather than by a list of facilities the module keeps: once
// every prerequisite the host names stands there is nothing left to ask for, and an account that kept
// buying shipyards it already had would never touch its economy again.
test('the chain empties once the host graph is satisfied', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());

    foreach (chainHostPrerequisites() as $machineName => $levels) {
        $this->planetSetObjectLevel($machineName, $levels['deepest']);
    }

    chainPowered();

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect($plan?->reason)->toStartWith('persona:');
});

// A chain step the host refuses used to cost the account its whole build capability. A player who
// cannot pay for a laboratory yet mines instead, and that is what the ordering has to fall through to
// rather than publishing nothing at all.
test('a chain step the account cannot pay for falls through to what it can afford', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());
    chainPowered();
    chainDrainDeuterium($this->currentUserId);

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan?->reason)->toStartWith('persona:')
        ->and(chainBuildOnce($this->currentUserId, $this->currentPlanetId))->toStartWith('persona:');
});

function chainProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 9_000 + $playerId,
        'enabled' => true,
    ]);
}

function chainPlenty(): Resources
{
    return app()->makeWith(Resources::class, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]);
}

/**
 * Enough capacity that the planet is not short, so a test can ask about the chain on its own. The
 * opening the account actually plays is covered by the reachability test and by the energy suite.
 */
function chainPowered(): void
{
    test()->planetSetObjectLevel('solar_plant', 20);
}

/** Plans one building, queues it through the host queue and lets the host finish it. */
function chainBuildOnce(int $playerId, int $planetId): string
{
    $plan = app(QueueableBuildingPlanner::class)->plan($playerId);
    $buildingId = (int) $plan?->buildingId;
    expect($plan)->not->toBeNull();

    $result = app(QueueAiBuilding::class)->handle($playerId, $planetId, $buildingId);
    expect($result->successful)->toBeTrue($result->reason);

    BuildingQueue::query()->where('planet_id', $planetId)->update(['time_end' => now()->subSecond()->getTimestamp()]);
    $player = app(PlayerGameStateService::class)->advance($playerId, $planetId);
    app(PlanetServiceFactory::class)->makeForPlayer($player, $planetId, false)->updateBuildingQueue();

    return $plan->reason;
}

function chainPlanet(int $playerId, int $planetId): PlanetService
{
    $player = app(PlayerGameStateService::class)->advance($playerId, $planetId);

    return app(PlanetServiceFactory::class)->makeForPlayer($player, $planetId, false);
}

/** @return list<int> */
function chainEconomyTargetIds(): array
{
    return array_map(static fn (FirstBuildingTarget $target): int => $target->value, FirstBuildingTarget::cases());
}

/**
 * Every building the host's requirement graph asks for, at the easiest and the deepest level any
 * ambition asks for. The deepest is what satisfies the whole graph; the easiest is the unlock the plan
 * starts with.
 *
 * @return array<string, array{easiest: int, deepest: int}>
 */
function chainHostPrerequisites(): array
{
    $levels = [];

    foreach ([...ObjectService::getResearchObjects(), ...ObjectService::getUnitObjects()] as $object) {
        foreach (ObjectService::getRecursiveRequirements($object->machine_name) as $machineName => $level) {
            $type = ObjectService::getObjectByMachineName($machineName)->type;

            // Technologies cannot stand on a planet, and they are not the building queue's step.
            if (!in_array($type, [GameObjectType::Building, GameObjectType::Station], true)) {
                continue;
            }

            $current = $levels[$machineName] ?? ['easiest' => $level, 'deepest' => $level];
            $levels[$machineName] = [
                'easiest' => min($current['easiest'], $level),
                'deepest' => max($current['deepest'], $level),
            ];
        }
    }

    return $levels;
}

function chainDrainDeuterium(int $playerId): void
{
    foreach (app(PlayerServiceFactory::class)->make($playerId, true)->planets->all() as $planet) {
        $planet->deductResources(app()->makeWith(Resources::class, ['deuterium' => $planet->deuterium()->get()]));
    }
}
