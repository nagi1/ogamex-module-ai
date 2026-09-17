<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\ExecuteAiIntentAction;
use Modules\AI\Actions\QueueAiTransferAction;
use Modules\AI\Actions\ScheduleAiIntentAction;
use Modules\AI\Contracts\QueueAiTransfer;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\CandidateActionFactory;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableTransfer;
use Modules\AI\Domain\Decision\QueueableTransferPlanner;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Factories\GameMissionFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiTransfer::class, QueueAiTransferAction::class);
});

/**
 * A colony whose next mine costs more than it holds is funded from the homeworld, which is the
 * ordinary ferry between own planets (X1). The target's shortfall is the host's price minus what is
 * already there, and the source is the planet that can spare it while keeping its reserve.
 */
test('a short colony is funded from the planet that can spare it', function (): void {
    transferProfile($this->currentUserId);
    $targetId = transferTarget($this->secondPlanetService);
    transferSource();

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableTransfer::class)
        ->and($plan?->sourcePlanetId)->toBe($this->currentPlanetId)
        ->and($plan?->targetPlanetId)->toBe($targetId)
        ->and($plan?->metal + $plan?->crystal)->toBeGreaterThanOrEqual(50_000);
});

/**
 * The in-flight netting (E4): what is already flying to the target is not sent again, so one hole is
 * funded by at most one shipment.
 */
test('a shipment already in flight is netted off the shortfall', function (): void {
    transferProfile($this->currentUserId);
    $targetId = transferTarget($this->secondPlanetService);
    transferSource();

    $first = app(QueueableTransferPlanner::class)->plan($this->currentUserId);
    expect($first)->toBeInstanceOf(QueueableTransfer::class);

    FleetMission::query()->forceCreate([
        'user_id' => $this->currentUserId,
        'planet_id_from' => $this->currentPlanetId,
        'planet_id_to' => $targetId,
        'mission_type' => \OGame\GameMissions\TransportMission::getTypeId(),
        'processed' => 0,
        'canceled' => 0,
        'time_arrival' => now()->addMinutes(30)->getTimestamp(),
        'metal' => $first->metal,
        'crystal' => $first->crystal,
        'deuterium' => $first->deuterium,
    ]);

    expect(app(QueueableTransferPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the ferry dispatches a transport mission over the host path', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    transferSource();

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableTransfer::class);

    $result = app(QueueAiTransfer::class)->handle(
        $this->currentUserId,
        $plan->sourcePlanetId,
        $plan->targetPlanetId,
        $plan->metal,
        $plan->crystal,
        $plan->deuterium,
    );

    expect($result->successful)->toBeTrue($result->reason)
        ->and(FleetMission::query()->where('mission_type', \OGame\GameMissions\TransportMission::getTypeId())->count())->toBe(1);
});

// A body near its storage cap ships its above-floor surplus to the most-developed
// planet, the reverse direction of the need-driven ferry (E9/X2).
test('a planet near its cap ships its surplus to the best-developed body', function (): void {
    transferProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 8);
    // The homeworld is the more developed body: it stays the drop.
    $this->planetSetObjectLevel('solar_plant', 20);

    $colony = $this->secondPlanetService;
    foreach (['metal_store' => 4, 'crystal_store' => 4, 'deuterium_store' => 4] as $machineName => $level) {
        $colony->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, true);
    }
    $colony->updateResources(false);
    $colony->updateResourceProductionStats(false);
    $colony->updateResourceStorageStats(false);
    $colony->addResources(new Resources(120_000, 120_000, 0));

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableTransfer::class)
        ->and($plan->sourcePlanetId)->toBe($colony->getPlanetId())
        ->and($plan->targetPlanetId)->toBe($this->currentPlanetId)
        ->and($plan->metal + $plan->crystal)->toBeGreaterThanOrEqual(50_000);
});

test('a source with no cargo hull cannot ferry', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('metal_store', 10);
    $this->planetSetObjectLevel('crystal_store', 10);
    $this->planetSetObjectLevel('deuterium_store', 10);

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableTransfer::class);

    $result = app(QueueAiTransfer::class)->handle(
        $this->currentUserId,
        $plan->sourcePlanetId,
        $plan->targetPlanetId,
        $plan->metal,
        $plan->crystal,
        $plan->deuterium,
    );

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe(AiQueueActionReason::NoTransportFleet->value);
});

// The intent arm: a scheduled transfer carries its source, target and shipment, and the executor
// ferries them over the host's transport path. Without a payload it re-plans, and with nothing worth
// ferrying it drops safely rather than queueing an empty mission.
test('the transfer intent ferries the scheduled shipment', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    transferSource();

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableTransfer::class);

    $workItem = transferWorkItem($this->currentUserId, [
        'source_planet_id' => $plan->sourcePlanetId,
        'target_planet_id' => $plan->targetPlanetId,
        'metal' => $plan->metal,
        'crystal' => $plan->crystal,
        'deuterium' => $plan->deuterium,
    ]);

    $result = app(ExecuteAiIntentAction::class)->execute($workItem, $plan->sourcePlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason)
        ->and($result[1]['source_planet_id'])->toBe($plan->sourcePlanetId);
});

test('a transfer intent without a payload re-plans from the account', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    transferSource();

    $result = app(ExecuteAiIntentAction::class)->execute(transferWorkItem($this->currentUserId, []), $this->currentPlanetId);

    expect($result[0]?->successful)->toBeTrue($result[0]?->reason);
});

test('a transfer intent with nothing worth ferrying drops safely', function (): void {
    transferProfile($this->currentUserId);

    $result = app(ExecuteAiIntentAction::class)->execute(transferWorkItem($this->currentUserId, []), $this->currentPlanetId);

    expect($result[0])->toBeNull()
        ->and($result[1])->toBe([])
        ->and($result[2])->toBe(0);
});

test('the ferry refuses an empty shipment and a fleet with no hold', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    transferSource();

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableTransfer::class);

    // Nothing to carry is not a shipment.
    expect(app(QueueAiTransfer::class)->handle($this->currentUserId, $plan->sourcePlanetId, $plan->targetPlanetId, 0, 0, 0)->reason)
        ->toBe(AiQueueActionReason::NoTransportFleet->value);
});

test('a combat-only fleet cannot ferry', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('metal_store', 10);
    $this->planetSetObjectLevel('crystal_store', 10);
    $this->planetSetObjectLevel('deuterium_store', 10);
    // A hull with no cargo hold is never taken: the ferry never moves the combat fleet.
    $this->planetAddUnit('light_fighter', 1);

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableTransfer::class);

    expect(app(QueueAiTransfer::class)->handle($this->currentUserId, $plan->sourcePlanetId, $plan->targetPlanetId, $plan->metal, $plan->crystal, $plan->deuterium)->reason)
        ->toBe(AiQueueActionReason::NoTransportFleet->value);
});

test('a ferry the host refuses is reported, not thrown', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    transferSource();

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableTransfer::class);

    // A shipment far past what the source owns or can carry: the host refuses it and the adapter
    // reports the refusal rather than letting the exception escape.
    $result = app(QueueAiTransfer::class)->handle($this->currentUserId, $plan->sourcePlanetId, $plan->targetPlanetId, 9_000_000, 9_000_000, 0);

    expect($result->successful)->toBeFalse();
});

// A flight the host refuses after the adapter has already picked the fleet travels through the
// catch: the ferry is reported as refused, never thrown.
test('a ferry the host refuses for fuel is reported, not thrown', function (): void {
    transferProfile($this->currentUserId);
    $targetId = transferTarget($this->secondPlanetService);
    transferSource();
    // A long flight with no deuterium left: the host's sanity check refuses it after the adapter
    // built the fleet, which is the only path that reaches the catch.
    Planet::query()->whereKey($targetId)->update(['galaxy' => 5, 'system' => 10, 'planet' => 15]);
    $this->planetDeductResources(new Resources(0, 0, 1_000_000));

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableTransfer::class);

    $result = app(QueueAiTransfer::class)->handle(
        $this->currentUserId,
        $plan->sourcePlanetId,
        $plan->targetPlanetId,
        $plan->metal,
        $plan->crystal,
        $plan->deuterium,
    );

    expect($result->successful)->toBeFalse();
});

// A hull with no cargo hold at all is skipped by the ferry loop rather than counted as capacity.
test('a hull with no cargo hold is never taken on a ferry', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('metal_store', 10);
    $this->planetSetObjectLevel('crystal_store', 10);
    $this->planetSetObjectLevel('deuterium_store', 10);
    $this->planetAddUnit('solar_satellite', 1);

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableTransfer::class);

    expect(app(QueueAiTransfer::class)->handle(
        $this->currentUserId,
        $plan->sourcePlanetId,
        $plan->targetPlanetId,
        $plan->metal,
        $plan->crystal,
        $plan->deuterium,
    )->reason)->toBe(AiQueueActionReason::NoTransportFleet->value);
});

// The schedule arm for a transfer carries the plan the session approved, so the ferry funds the
// body the decision saw rather than a re-decided shortfall.
test('the transfer intent schedules the shipment the plan approved', function (): void {
    $profile = transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    transferSource();

    $session = AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'transfer-schedule:' . $this->currentUserId . ':' . uniqid(),
        'state' => AiWorkState::Pending,
    ]);

    app(ScheduleAiIntentAction::class)->handle($profile, $session, transferDecisionTrace($this->currentUserId, $this->currentPlanetId));

    $intent = AiWorkItem::query()->where('idempotency_key', 'intent:session:' . $session->id)->first();

    expect($intent)->not->toBeNull()
        ->and($intent?->kind)->toBe(AiWorkKind::Transfer)
        ->and($intent?->payload)->toHaveKeys(['source_planet_id', 'target_planet_id', 'metal', 'crystal', 'deuterium']);
});

// The candidate factory offers a transfer when the account can ferry, and none when it cannot.
test('the factory offers a transfer when the account can ferry', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);
    transferSource();

    $generation = app(CandidateActionFactory::class)->create(transferSnapshot($this->currentUserId, $this->currentPlanetId));

    expect(array_map(static fn ($candidate): AiCandidateActionType => $candidate->type, $generation->candidates))
        ->toContain(AiCandidateActionType::Transfer);
});

// The ferry iterates the target's own row and skips it, then funds the shortfall from the other body.
test('a homeworld shortfall is funded from the colony', function (): void {
    transferProfile($this->currentUserId);
    $homeId = transferTarget($this->planetService);
    $this->secondPlanetService->addResources(new Resources(1_000_000, 1_000_000, 1_000_000));

    $plan = app(QueueableTransferPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableTransfer::class)
        ->and($plan?->sourcePlanetId)->toBe($this->secondPlanetService->getPlanetId())
        ->and($plan?->targetPlanetId)->toBe($homeId);
});

// A shortfall no other body can spare stays unfunded: the source pass finds nothing and the plan
// falls through rather than inventing a shipment.
test('a shortfall no other planet can spare is left unfunded', function (): void {
    transferProfile($this->currentUserId);
    transferTarget($this->secondPlanetService);

    expect(app(QueueableTransferPlanner::class)->plan($this->currentUserId))->toBeNull();
});

// A planet whose own next step is beyond the payback horizon is not a shortfall the ferry reads,
// so nothing is shipped for it either.
test('a planet with no next step is skipped by the ferry', function (): void {
    transferProfile($this->currentUserId);
    transferSaturate($this->planetService);

    expect(app(QueueableTransferPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('a single-body account has nothing to ferry', function (): void {
    transferProfile($this->currentUserId);
    Planet::query()->where('user_id', $this->currentUserId)->where('id', '!=', $this->currentPlanetId)->update(['destroyed' => 1]);

    expect(app(QueueableTransferPlanner::class)->plan($this->currentUserId))->toBeNull();
});

function transferProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 8_000 + $playerId,
        'enabled' => true,
    ]);
}

/** @param array<string, mixed> $payload */
function transferWorkItem(int $playerId, array $payload): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $playerId,
        'kind' => AiWorkKind::Transfer,
        'due_at' => now(),
        'idempotency_key' => 'transfer-intent:' . $playerId . ':' . uniqid(),
        'state' => AiWorkState::Pending,
        'payload' => $payload,
    ]);
}

/**
 * Shapes the target colony so its next step is an expensive mine and it holds no resources to pay
 * for it: the facility chain is satisfied, the planet covers its energy, the warehouse is deep enough
 * not to overflow, and the mines are deep enough that the next level clears the minimum shipment.
 */
function transferTarget(?PlanetService $target): int
{
    expect($target)->not->toBeNull();

    foreach (transferPrerequisites() as $machineName => $level) {
        $object = ObjectService::getObjectByMachineName($machineName);
        if ($object->type === GameObjectType::Research) {
            test()->playerSetResearchLevel($machineName, $level);
            continue;
        }

        $target->setObjectLevel($object->id, $level, true);
    }

    foreach (['solar_plant' => 30, 'metal_mine' => 18, 'crystal_mine' => 18, 'deuterium_synthesizer' => 18] as $machineName => $level) {
        $target->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, true);
    }
    foreach (['metal_store' => 25, 'crystal_store' => 25, 'deuterium_store' => 25] as $machineName => $level) {
        $target->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, true);
    }

    $target->updateResources(false);
    $target->updateResourceProductionStats(false);
    $target->updateResourceStorageStats(false);
    $target->deductResources(new Resources($target->metal()->get(), $target->crystal()->get(), $target->deuterium()->get()));

    return $target->getPlanetId();
}

/** The homeworld: funded, warehouse sized to hold the shipment, and a cargo fleet to carry it. */
function transferSource(): void
{
    test()->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    test()->planetAddUnit('large_cargo', 8);
    test()->planetSetObjectLevel('metal_store', 10);
    test()->planetSetObjectLevel('crystal_store', 10);
    test()->planetSetObjectLevel('deuterium_store', 10);
}

/** @return array<string, int> the deepest level any ambition asks for, per prerequisite */
function transferPrerequisites(): array
{
    $levels = [];

    foreach ([...ObjectService::getResearchObjects(), ...ObjectService::getUnitObjects()] as $object) {
        foreach (ObjectService::getRecursiveRequirements($object->machine_name) as $machineName => $level) {
            $levels[$machineName] = max($levels[$machineName] ?? 0, $level);
        }
    }

    // A host mission that waits on a research is a chain step too, so the target's next step only
    // becomes an expensive mine once that research stands.
    foreach (GameMissionFactory::getAllMissions() as $mission) {
        foreach ($mission::getRequiredResearch() as $machineName => $level) {
            $levels[$machineName] = max($levels[$machineName] ?? 0, $level);
            foreach (ObjectService::getRecursiveRequirements($machineName) as $prerequisite => $prerequisiteLevel) {
                $levels[$prerequisite] = max($levels[$prerequisite] ?? 0, $prerequisiteLevel);
            }
        }
    }

    return $levels;
}

/** A decision trace whose selected action is Transfer, for the schedule arm. */
function transferDecisionTrace(int $playerId, int $planetId): DecisionTrace
{
    $candidate = app()->makeWith(CandidateAction::class, [
        'type' => AiCandidateActionType::Transfer,
        'reason' => 'transfer-fixture',
        'parameters' => [],
        'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]);
    $selected = app()->makeWith(ScoredCandidate::class, ['candidate' => $candidate, 'score' => 1.0, 'components' => []]);

    return app()->makeWith(DecisionTrace::class, [
        'perception' => app()->makeWith(PerceptionSnapshot::class, [
            'playerId' => $playerId,
            'observedAt' => CarbonImmutable::instance(now()),
            'planets' => [],
            'targetReports' => [],
            'availableActions' => [],
            'fleetsaveEligible' => false,
            'recoveryFactor' => 0.0,
            'sourceTimestamps' => [],
            'inboundFleets' => [],
        ]),
        'candidates' => [$selected],
        'selected' => $selected,
        'rejections' => [],
        'inputHash' => 'transfer-fixture',
    ]);
}

/** A minimal perception over the account the ferry reads; the transfer eligibility is the planner's. */
function transferSnapshot(int $playerId, int $planetId): PerceptionSnapshot
{
    return app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $playerId,
        'observedAt' => CarbonImmutable::instance(now()),
        'planets' => [['id' => $planetId, 'resources' => ['metal' => 5_000, 'crystal' => 5_000, 'deuterium' => 5_000]]],
        'targetReports' => [],
        'availableActions' => array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => false,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => [],
        'fleetSlotsFree' => 2,
    ]);
}

/**
 * A planet whose own next step is beyond every horizon: deep mines repay nothing inside the payback
 * cap, the energy is covered, the chain is satisfied and the warehouse is empty, so the ferry's
 * next-step read finds no shortfall at all.
 */
function transferSaturate(PlanetService $planet): void
{
    foreach (transferPrerequisites() as $machineName => $level) {
        $object = ObjectService::getObjectByMachineName($machineName);
        if ($object->type === GameObjectType::Research) {
            test()->playerSetResearchLevel($machineName, $level);
            continue;
        }

        $planet->setObjectLevel($object->id, $level, true);
    }
    foreach (['solar_plant' => 40, 'metal_mine' => 25, 'crystal_mine' => 25, 'deuterium_synthesizer' => 25, 'metal_store' => 30, 'crystal_store' => 30, 'deuterium_store' => 30] as $machineName => $level) {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, true);
    }

    $planet->updateResources(false);
    $planet->updateResourceProductionStats(false);
    $planet->updateResourceStorageStats(false);
    $planet->deductResources(new Resources($planet->metal()->get(), $planet->crystal()->get(), $planet->deuterium()->get()));
}
