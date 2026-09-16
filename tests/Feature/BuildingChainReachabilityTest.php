<?php

use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Actions\QueueAiResearchAction;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Contracts\QueueAiResearch;
use Modules\AI\Domain\Decision\BuildCandidate;
use Modules\AI\Domain\Decision\FacilityChain;
use Modules\AI\Domain\Decision\QueueableBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\ExpeditionMission;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\BuildingQueue;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiBuilding::class, QueueAiBuildingAction::class);
    app()->bind(QueueAiResearch::class, QueueAiResearchAction::class);
});

/**
 * A capability the account can never reach is not a capability, so this suite follows the account
 * rather than the plan: each round it plans the next step -- a building or a technology -- queues it
 * through the real host queue that accepts it, lets the host finish it, and then reads what the
 * account actually owns.
 *
 * Every expectation below is computed from the host's own catalogue, which is also the proof that the
 * module keeps no list of its own: a planning rule that named its own buildings would satisfy itself
 * here and drift the moment the catalogue changed.
 */
test('a funded account reaches a research lab, a robotics factory, a shipyard and a technology', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());
    chainStoraged();

    $steps = [];
    foreach (range(1, 20) as $round) {
        $steps[] = chainQueueOnce($this->currentUserId, $this->currentPlanetId);
    }

    // The assertion is reachability, not a script. Every step the account queued was either
    // capacity, because the host throttles a planet that cannot cover its mines, or a prerequisite the
    // host's own requirement graph names -- nothing the module decided for itself.
    $chainSteps = array_values(array_filter($steps, static fn (string $step): bool => str_starts_with($step, 'chain:')));
    $prerequisites = chainHostPrerequisites();

    expect($chainSteps)->not->toBeEmpty();
    foreach ($chainSteps as $step) {
        expect(chainHostPrerequisites())->toHaveKey(substr($step, strlen('chain:')));
    }

    $planet = chainPlanet($this->currentUserId, $this->currentPlanetId);

    expect($planet->getObjectLevel('research_lab'))->toBeGreaterThanOrEqual(1)
        ->and($planet->getObjectLevel('robot_factory'))->toBeGreaterThanOrEqual(2)
        ->and($planet->getObjectLevel('shipyard'))->toBeGreaterThanOrEqual(1);

    // A technology the host's graph asks for cannot stand on a planet, so the only proof it was
    // reached is the level the host reports for it -- which is what makes research a capability the
    // account actually has rather than one it merely published.
    $researched = array_values(array_filter(
        $chainSteps,
        static fn (string $step): bool => ObjectService::getObjectByMachineName(substr($step, strlen('chain:')))->type === GameObjectType::Research,
    ));

    expect($researched)->not->toBeEmpty();
    foreach ($researched as $step) {
        expect(chainPlanet($this->currentUserId, $this->currentPlanetId)->getPlayer()->getResearchLevel(substr($step, strlen('chain:'))))->toBeGreaterThanOrEqual($prerequisites[substr($step, strlen('chain:'))]['easiest']);
    }
});

// The chain is derived, not declared: the building the planner asks for is one the host's own
// requirement graph lists for something this account could produce, so a module that adds a ship or a
// technology extends the plan without a line of module policy changing.
test('the chain asks for a prerequisite the host names', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());
    chainPowered();
    chainStoraged();

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);
    $machineName = chainStepMachineName($plan);

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
    chainStoraged();

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);
    $machineName = chainStepMachineName($plan);

    expect(chainHostPrerequisites()[$machineName]['easiest'])->toBe(1);
});

// One ambition at a time. Asking for the union of every ambition's prerequisites is a ladder with no
// top -- the catalogue always names one more deep unlock -- so the chain never finished, the economy
// never got a turn, and the grand test measured the result: level-seven shipyards standing over
// level-three mines while metal piled up unspent. The chain therefore serves the cheapest ambition the
// account cannot yet produce and asks for nothing outside that ambition's own prerequisites.
test('the chain asks only for the ambition in hand, not every ambition at once', function (): void {
    chainProfile($this->currentUserId);
    chainPowered();
    // A mine of every kind, so the planet has income of every resource: that is what keeps a producer
    // step out of the list, leaving the ordered steps to be read as the ambition's prerequisites alone.
    foreach (['metal_mine', 'crystal_mine', 'deuterium_synthesizer'] as $mined) {
        $this->planetSetObjectLevel($mined, 1);
    }

    $planet = chainPlanet($this->currentUserId, $this->currentPlanetId);
    $goal = chainCheapestUnmetAmbition($planet);

    $reasons = array_map(
        static fn (BuildCandidate $step): string => $step->reason,
        app(FacilityChain::class)->pending($planet),
    );

    // The ambition's own prerequisites ...
    $expected = array_map(
        static fn (string $machineName): string => 'chain:' . $machineName,
        array_keys(ObjectService::getRecursiveRequirements($goal->machine_name)),
    );

    // ... plus the research the host's missions wait on and that research's own prerequisites. A leaf
    // technology no unit needs becomes a step because a mission gates on it, and nothing outside this
    // ambition and those missions leaks in.
    foreach (GameMissionFactory::getAllMissions() as $mission) {
        foreach ($mission::getRequiredResearch() as $machineName => $level) {
            $expected[] = 'capability:' . $machineName;
            foreach (array_keys(ObjectService::getRecursiveRequirements($machineName)) as $prerequisite) {
                $expected[] = 'chain:' . $prerequisite;
            }
        }
    }

    expect(array_values(array_unique($reasons)))->toEqualCanonicalizing(array_values(array_unique($expected)));
});

// The gap the grand run measured: a technology no unit needs -- astrophysics -- was never reached,
// so colonise and expedition stayed permanently unreachable however long the account played. A host
// mission that waits on a technology makes that technology a chain step, so the account climbs it
// like any other prerequisite and the capability opens.
test('a research a host mission gates on is a chain step even when no unit needs it', function (): void {
    chainProfile($this->currentUserId);
    chainPowered();
    chainStoraged();

    $gated = ExpeditionMission::getRequiredResearch();
    expect($gated)->not->toBeEmpty();

    $planet = chainPlanet($this->currentUserId, $this->currentPlanetId);
    $reasons = array_map(
        static fn (BuildCandidate $step): string => $step->reason,
        app(FacilityChain::class)->pending($planet),
    );

    foreach (array_keys($gated) as $machineName) {
        expect($reasons)->toContain('capability:' . $machineName);
    }
});

// The chain is bounded by the host's graph rather than by a list of facilities the module keeps: once
// every prerequisite the host names stands there is nothing left to ask for, and an account that kept
// buying shipyards it already had would never touch its economy again.
test('the chain empties once the host graph is satisfied', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());

    foreach (chainHostPrerequisites() as $machineName => $levels) {
        $isResearch = ObjectService::getObjectByMachineName($machineName)->type === GameObjectType::Research;
        $isResearch
            ? $this->playerSetResearchLevel($machineName, $levels['deepest'])
            : $this->planetSetObjectLevel($machineName, $levels['deepest']);
    }

    chainPowered();
    chainStoraged();

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and(chainStepId($plan))->toBeIn(chainEconomyTargetIds())
        ->and($plan?->reason)->toMatch('/^(economy|storage):/');
});

// A chain step the host refuses used to cost the account its whole build capability. A player who
// cannot pay for a laboratory yet mines instead, and that is what the ordering has to fall through to
// rather than publishing nothing at all.
test('a slot-bound account reaches the technology that raises its fleet ceiling', function (): void {
    chainProfile($this->currentUserId);
    // No computer technology yet, so the ceiling object is the step the chain must grow.
    $this->playerSetResearchLevel('computer_technology', 0);
    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $planet = array_values($player->planets->all())[0];

    // Fill every fleet slot with an active, non-missile mission, so the chain has to grow the ceiling
    // the host publishes behind MAX_FLEET_SLOTS rather than send a dispatch the host would refuse.
    for ($slot = 0; $slot < $player->getFleetSlotsMax(); $slot++) {
        DB::table('fleet_missions')->insert([
            'user_id' => $this->currentUserId,
            'planet_id_from' => $this->currentPlanetId,
            'mission_type' => 1,
            'time_arrival' => now()->addHour()->getTimestamp(),
        ]);
    }

    expect($player->getFleetSlotsInUse())->toBeGreaterThanOrEqual($player->getFleetSlotsMax());

    $steps = app(FacilityChain::class)->pending($planet);
    $machineNames = array_map(static fn (BuildCandidate $step): string => ObjectService::getObjectById($step->buildingId)->machine_name, $steps);

    expect($machineNames)->toContain('computer_technology');
});

test('a chain step the account cannot pay for falls through to what it can afford', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());
    chainPowered();
    chainStoraged();
    chainDrainDeuterium($this->currentUserId);

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    // Whatever it fell through to, the host accepted it: the account kept a capability instead of
    // losing the whole chain to one step it could not pay for.
    expect($plan)->not->toBeNull()
        ->and(chainQueueOnce($this->currentUserId, $this->currentPlanetId))->toBe($plan?->reason);
});

// A warehouse that is already full is a spend signal, not a warehouse signal: the surplus above
// capacity is fully lootable, so the planner falls through to the routine economy (chain or mine)
// that spends it instead of buying a bigger warehouse the next windfall would fill again.
test('a full warehouse is spent, not grown', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());
    chainPowered();
    $this->planetSetObjectLevel('metal_mine', 5);

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan?->reason)->not->toStartWith('storage:')
        ->and($plan?->reason)->toMatch('/^(chain|economy):/');
});

// Storage is the one answer checked across every planet before any routine step, but only while a
// warehouse will actually fill during the absence: a near-full warehouse on the newest colony still
// outranks a mine or facility on the homeworld.
test('a warehouse that will fill during the absence preempts a routine step on another planet', function (): void {
    chainProfile($this->currentUserId);
    $this->planetAddResources(chainPlenty());
    chainStoraged();
    chainPowered();

    expect($this->secondPlanetService)->not->toBeNull();
    $colony = $this->secondPlanetService;
    // Persist the levels so the planner's fresh read sees the colony's real production.
    $colony->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 20, true);
    $colony->setObjectLevel(ObjectService::getObjectByMachineName('metal_mine')->id, 20, true);
    $colony->updateResourceProductionStats(false);
    $colony->updateResourceStorageStats(false);

    // Fill all but one unit of capacity, so the store overflows as soon as it produces anything:
    // a future fill grows the warehouse, a present fill spends the surplus.
    $colony->updateResourceStorageStats(false);
    $headroom = $colony->metalStorage()->get() - $colony->getResources()->metal->get();
    $colony->addResources(new Resources(max(0.0, $headroom - 1.0), 0, 0));

    $plan = app(QueueableBuildingPlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan->planetId)->toBe($colony->getPlanetId())
        ->and($plan?->reason)->toStartWith('storage:');
});

/**
 * The cheapest host ambition this planet cannot produce yet -- the same reading the chain itself
 * takes, restated from the host catalogue so the expectation is never the implementation.
 */
function chainCheapestUnmetAmbition(PlanetService $planet): GameObject
{
    $objects = [...ObjectService::getResearchObjects(), ...ObjectService::getUnitObjects()];
    usort($objects, static fn (GameObject $left, GameObject $right): int => $left->price->resources->sum() <=> $right->price->resources->sum());

    foreach ($objects as $object) {
        foreach (ObjectService::getRecursiveRequirements($object->machine_name) as $machineName => $level) {
            $current = ObjectService::getObjectByMachineName($machineName)->type === GameObjectType::Research
                ? ($planet->getPlayer()?->getResearchLevel($machineName) ?? 0)
                : $planet->getObjectLevel($machineName);

            if ($current < $level) {
                return $object;
            }
        }
    }

    throw new RuntimeException('the host catalogue offers no ambition this planet cannot produce');
}

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

/**
 * Enough warehouse that a funded balance is not "about to overflow", so a test can ask about the
 * chain on its own. The overflow case is the storage-preemption test above.
 */
function chainStoraged(): void
{
    test()->planetSetObjectLevel('metal_store', 10);
    test()->planetSetObjectLevel('crystal_store', 10);
    test()->planetSetObjectLevel('deuterium_store', 10);
}

/** Plans one step, queues it through the host queue that accepts it and lets the host finish it. */
function chainQueueOnce(int $playerId, int $planetId): string
{
    $plan = app(QueueableBuildingPlanner::class)->plan($playerId);
    expect($plan)->not->toBeNull();

    $stepId = chainStepId($plan);
    $result = $plan instanceof QueueableResearch
        ? app(QueueAiResearch::class)->handle($playerId, $planetId, $stepId)
        : app(QueueAiBuilding::class)->handle($playerId, $planetId, $stepId);
    expect($result->successful)->toBeTrue($result->reason);

    BuildingQueue::query()->where('planet_id', $planetId)->update(['time_end' => now()->subSecond()->getTimestamp()]);
    ResearchQueue::query()->where('planet_id', $planetId)->update(['time_end' => now()->subSecond()->getTimestamp()]);
    $player = app(PlayerGameStateService::class)->advance($playerId, $planetId);
    $player->updateResearchQueue();
    app(PlanetServiceFactory::class)->makeForPlayer($player, $planetId, false)->updateBuildingQueue();

    return $plan->reason;
}

/** The host object id a planned step names, whichever queue it belongs to. */
function chainStepId(QueueableBuilding|QueueableResearch|null $plan): int
{
    return $plan instanceof QueueableResearch ? $plan->researchId : (int) $plan?->buildingId;
}

function chainStepMachineName(QueueableBuilding|QueueableResearch|null $plan): string
{
    return ObjectService::getObjectById(chainStepId($plan))->machine_name;
}

function chainPlanet(int $playerId, int $planetId): PlanetService
{
    $player = app(PlayerGameStateService::class)->advance($playerId, $planetId);

    return app(PlanetServiceFactory::class)->makeForPlayer($player, $planetId, false);
}

/** @return list<int> the ids the economy ranking can offer: everything the host produces or stores */
function chainEconomyTargetIds(): array
{
    return array_map(
        static fn (GameObject $object): int => (int) $object->id,
        [...ObjectService::getGameObjectsWithProduction(), ...ObjectService::getBuildingObjectsWithStorage()],
    );
}

/**
 * Every prerequisite the host's requirement graph asks for, at the easiest and the deepest level any
 * ambition asks for. The deepest is what satisfies the whole graph; the easiest is the unlock the plan
 * starts with. Buildings and technologies are both in here, because a prerequisite is a prerequisite
 * whichever queue ends up taking it.
 *
 * @return array<string, array{easiest: int, deepest: int}>
 */
function chainHostPrerequisites(): array
{
    $levels = [];

    $merge = static function (string $machineName, int $level) use (&$levels): void {
        $current = $levels[$machineName] ?? ['easiest' => $level, 'deepest' => $level];
        $levels[$machineName] = [
            'easiest' => min($current['easiest'], $level),
            'deepest' => max($current['deepest'], $level),
        ];
    };

    foreach ([...ObjectService::getResearchObjects(), ...ObjectService::getUnitObjects()] as $object) {
        foreach (ObjectService::getRecursiveRequirements($object->machine_name) as $machineName => $level) {
            $merge($machineName, $level);
        }
    }

    // A host mission that waits on a research makes that research a chain step, so "the graph is
    // satisfied" also has to reach the mission's own research and that research's prerequisites.
    foreach (GameMissionFactory::getAllMissions() as $mission) {
        foreach ($mission::getRequiredResearch() as $machineName => $level) {
            $merge($machineName, $level);
            foreach (ObjectService::getRecursiveRequirements($machineName) as $prerequisite => $prerequisiteLevel) {
                $merge($prerequisite, $prerequisiteLevel);
            }
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
