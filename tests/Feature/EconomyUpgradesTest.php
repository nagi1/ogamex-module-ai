<?php

use Modules\AI\Actions\RecordAiExperienceOutcomeAction;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Decision\BuildCandidate;
use Modules\AI\Domain\Decision\EconomyUpgrades;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\SystemAiClock;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The economy decision, tested against the host's own catalogue and numbers.
 *
 * The module's own bindings are not active in this suite, so they are wired here the way
 * AIServiceProvider::register() wires them.
 */
beforeEach(function (): void {
    // The circuit breaker behind an optional experience driver reads the module clock.
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
    config(['ai.cognition.experience.driver' => 'native']);
});

/**
 * Every candidate must come from the host's catalogue and say where it came from, because the point
 * of the slice is that no building id, machine name or price is a source of truth in module code.
 */
test('every candidate is a host object, named by its own machine name', function (): void {
    $candidates = economyPending($this->planetService, economyProfile($this->currentUserId));

    expect($candidates)->not->toBeEmpty();

    foreach ($candidates as $candidate) {
        $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;

        expect($candidate->reason)->toBeIn(['economy:' . $machineName, 'storage:' . $machineName]);
    }
});

test('one account has one order it can reproduce', function (): void {
    $profile = economyProfile($this->currentUserId);

    expect(economyRanking($this->planetService, $profile))
        ->toBe(economyRanking($this->planetService, $profile));
});

/**
 * The persona's taste is a seeded nudge around the arithmetic, so accounts with the same host data and
 * different seeds must be able to differ -- while never inventing, dropping or substituting a
 * candidate, because taste is not a reason for a capability to be reachable.
 */
test('two seeds rank the same host objects, and may order them differently', function (): void {
    $first = economyRanking($this->planetService, economyProfile($this->currentUserId, AiSkillBand::Novice, 6));
    $second = economyRanking($this->planetService, economyProfile($this->currentUserId + 100_001, AiSkillBand::Novice, 9));

    expect(economySorted($first))->toBe(economySorted($second));
});

/**
 * Payback is the whole rule, and it has a horizon: a level that would not repay itself inside the
 * time the account intends to keep playing is not worth buying, which is how players stop mining.
 */
test('a planet whose next levels never repay themselves wants nothing from the economy', function (): void {
    economyDeepPlanet($this->planetService);

    expect(economyPending($this->planetService, economyProfile($this->currentUserId)))->toBe([]);
});

/**
 * A warehouse exists so production does not stop while nobody is watching, so it is worth buying
 * exactly when it would fill inside the time the account is away -- and a resource this planet does
 * not produce can never fill, which is why the deuterium tank is not offered here.
 */
test('a warehouse about to fill is offered before the next upgrade', function (): void {
    // Capacity and production are the host's numbers at full output, so the planet needs to be able
    // to cover what its mines draw -- otherwise the host throttles them and nothing ever fills.
    $this->planetSetObjectLevel('solar_plant', 25);
    $this->planetSetObjectLevel('metal_mine', 20);
    $this->planetSetObjectLevel('crystal_mine', 20);
    economyRefresh($this->planetService);

    $ids = economyStorageIds($this->planetService);
    $candidates = economyPending($this->planetService, economyProfile($this->currentUserId));

    expect($candidates)->not->toBeEmpty()
        ->and($candidates[0]->buildingId)->toBe($ids['metal'])
        ->and($candidates[0]->reason)->toBe('storage:' . ObjectService::getObjectById($ids['metal'])->machine_name)
        ->and(array_map(static fn (BuildCandidate $candidate): int => $candidate->buildingId, $candidates))
        ->toContain($ids['crystal'])
        ->not->toContain($ids['deuterium']);
});

/**
 * Remembered outcomes reach a real decision, and they stay inside their bound: the term may move a
 * payback by at most a fifth either way, which is deliberately smaller than the gap between two
 * different mines on a fresh planet. Evidence therefore resolves the near-tie between two nearly equal
 * upgrades, and can never itself make a capability reachable or unreachable.
 */
test('remembered outcomes never add or drop a candidate', function (): void {
    $profile = economyProfile($this->currentUserId);
    $baseline = economyRanking($this->planetService, $profile);

    economySeedOutcome($this->currentUserId, 9001, $baseline[1], 1.0, 0.0, AiExperienceOutcome::Succeeded);
    economySeedOutcome($this->currentUserId, 9002, $baseline[2], -1.0, 0.0, AiExperienceOutcome::Failed);

    expect(economySorted(economyRanking($this->planetService, $profile)))->toBe(economySorted($baseline));
});

/**
 * A young planet's next mine can cost more than its warehouse holds, so the build is never
 * affordable however long the mines run. The store that raises the blocking resource is queued
 * first (E8) -- a player upgrades the warehouse before the mine that will not fit.
 */
test('a build whose price exceeds storage is preceded by the store that raises it', function (): void {
    $profile = economyProfile($this->currentUserId);

    // Developed mines against floor-level warehouses: every next mine costs
    // more than its store holds, so the store that raises the blocking resource
    // (the cheapest next build, metal) is the answer.
    $this->planetSetObjectLevel('metal_store', 0);
    $this->planetSetObjectLevel('crystal_store', 0);
    $this->planetSetObjectLevel('deuterium_store', 0);
    $this->planetSetObjectLevel('metal_mine', 14);
    $this->planetSetObjectLevel('crystal_mine', 14);
    $this->planetSetObjectLevel('deuterium_synthesizer', 11);
    $this->planetSetObjectLevel('solar_plant', 25);
    economyRefresh($this->planetService);

    $ids = economyStorageIds($this->planetService);
    $candidates = app(EconomyUpgrades::class)->storageForPrice($this->planetService, $profile);

    expect($candidates)->not->toBeEmpty()
        ->and($candidates[0]->buildingId)->toBe($ids['metal'])
        ->and($candidates[0]->reason)->toBe('storage:' . ObjectService::getObjectById($ids['metal'])->machine_name);
});

/** With a warehouse that already fits the next build, there is nothing to prepend. */
test('a build whose price fits storage needs no storage prepend', function (): void {
    $profile = economyProfile($this->currentUserId);

    $this->planetSetObjectLevel('metal_store', 10);
    $this->planetSetObjectLevel('metal_mine', 5);
    $this->planetSetObjectLevel('solar_plant', 20);
    economyRefresh($this->planetService);

    expect(app(EconomyUpgrades::class)->storageForPrice($this->planetService, $profile))->toBe([]);
});

/** Weight zero is the ablation switch: with it off, evidence may not move anything. */
test('the experience weight is the ablation switch', function (): void {
    $profile = economyProfile($this->currentUserId);
    $baseline = economyRanking($this->planetService, $profile);
    economySeedOutcome($this->currentUserId, 9003, $baseline[0], -1.0, 0.0, AiExperienceOutcome::Failed);

    config(['ai.cognition.experience.decision_weight' => 0]);

    expect(economyRanking($this->planetService, $profile))->toBe($baseline);
});

/**
 * E9: a full warehouse the economy cannot spend on a mine is dumped into research, and the dump is
 * a technology from the host's own catalogue that spends the capped resource. A metal-capped planet
 * therefore never gets a crystal-only technology, and the reason names the host object it came from.
 */
test('a full warehouse offers a research dump that spends the capped resource', function (): void {
    $profile = economyProfile($this->currentUserId);

    $this->planetSetObjectLevel('solar_plant', 25);
    $this->planetSetObjectLevel('metal_mine', 10);
    $this->planetSetObjectLevel('crystal_mine', 10);
    $this->planetSetObjectLevel('deuterium_synthesizer', 5);
    $this->planetAddResources(new Resources(10_000_000, 0, 0));
    economyRefresh($this->planetService);

    $candidates = app(EconomyUpgrades::class)->spendSurplus($this->planetService, $profile);
    $dumps = array_values(array_filter(
        $candidates,
        static fn (BuildCandidate $candidate): bool => ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research,
    ));

    expect($dumps)->not->toBeEmpty();

    foreach ($dumps as $dump) {
        $object = ObjectService::getObjectById($dump->buildingId);
        $price = ObjectService::getObjectPrice($object->machine_name, $this->planetService);

        expect($object->type)->toBe(GameObjectType::Research)
            ->and($dump->reason)->toBe('economy:dump:' . $object->machine_name)
            ->and($price->metal->get())->toBeGreaterThan(0.0);
    }
});

function economyProfile(int $playerId, AiSkillBand $band = AiSkillBand::Standard, int $seed = 42): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => $band,
        'random_seed' => $seed,
        'enabled' => true,
    ]);
}

/** @return list<BuildCandidate> */
function economyPending(PlanetService $planet, AiProfile $profile): array
{
    return app(EconomyUpgrades::class)->pending($planet, $profile);
}

/** @return list<int> */
function economyRanking(PlanetService $planet, AiProfile $profile): array
{
    return array_map(
        static fn (BuildCandidate $candidate): int => $candidate->buildingId,
        economyPending($planet, $profile),
    );
}

/** @param list<int> $ids */
function economySorted(array $ids): array
{
    sort($ids);

    return $ids;
}

/**
 * A planet deep enough that every next mine takes years to repay itself, with warehouses large
 * enough that nothing would overflow either.
 */
function economyDeepPlanet(PlanetService $planet): void
{
    // The deep planet still has to cover its energy, or the host throttles the mines and the payback
    // question is asked about a fraction of their output.
    $planet->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 30, false);

    foreach (['metal_mine' => 45, 'crystal_mine' => 45, 'deuterium_synthesizer' => 45] as $machineName => $level) {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, false);
    }

    foreach (economyStorageIds($planet) as $objectId) {
        $planet->setObjectLevel($objectId, 25, false);
    }

    economyRefresh($planet);
}

/**
 * The in-memory refresh a planning pass performs before it asks: production and capacity are stored
 * columns the host recomputes when it touches a planet, so a fixture that sets levels directly has to
 * recompute them the same way.
 */
function economyRefresh(PlanetService $planet): void
{
    $planet->updateResources(false);
    $planet->updateResourceProductionStats(false);
    $planet->updateResourceStorageStats(false);
}

/**
 * Which storage object raises which resource, asked of the host's own capacity formula rather than
 * stated as a list of names.
 *
 * @return array{metal: int, crystal: int, deuterium: int}
 */
function economyStorageIds(PlanetService $planet): array
{
    $ids = [];

    foreach (ObjectService::getBuildingObjectsWithStorage() as $object) {
        $level = $planet->getObjectLevel($object->machine_name);
        $added = $planet->getBuildingMaxStorage($object->machine_name, $level + 1);
        $current = $planet->getBuildingMaxStorage($object->machine_name);

        if ($added->metal->get() > $current->metal->get()) {
            $ids['metal'] = (int) $object->id;
        }
        if ($added->crystal->get() > $current->crystal->get()) {
            $ids['crystal'] = (int) $object->id;
        }
        if ($added->deuterium->get() > $current->deuterium->get()) {
            $ids['deuterium'] = (int) $object->id;
        }
    }

    return $ids;
}

function economySeedOutcome(
    int $playerId,
    int $sourceId,
    int $objectId,
    float $utility,
    float $uncertainty,
    AiExperienceOutcome $outcome,
): int {
    return app(RecordAiExperienceOutcomeAction::class)->handle(
        $playerId,
        $sourceId,
        AiExperienceCaseFamily::BuildingUpgrade,
        $outcome,
        AiExperienceFeatureVersion::BuildingUpgradeV1->value,
        AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
        [
            AiBuildingExperienceFeature::PlanetId->value => 1,
            AiBuildingExperienceFeature::ObjectId->value => $objectId,
            AiBuildingExperienceFeature::TargetLevel->value => 5,
        ],
        $utility,
        $uncertainty,
    )->id;
}
