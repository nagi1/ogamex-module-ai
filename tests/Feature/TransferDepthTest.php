<?php

use Modules\AI\Actions\ExecuteAiIntentAction;
use Modules\AI\Actions\QueueAiTransferAction;
use Modules\AI\Contracts\QueueAiTransfer;
use Modules\AI\Domain\Decision\QueueableTransfer;
use Modules\AI\Domain\Decision\QueueableTransferPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Factories\GameMissionFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\FleetMission;
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
