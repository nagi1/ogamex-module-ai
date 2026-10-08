<?php

use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Domain\Decision\BuildCandidate;
use Modules\AI\Domain\Decision\FacilityChain;
use Modules\AI\Domain\Decision\QueueableJumpGate;
use Modules\AI\Domain\Decision\QueueableJumpGatePlanner;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
});

function jumpGateProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 9_000 + $playerId,
        'enabled' => true,
    ]);
}

/**
 * Two own moons, each carrying a jump gate, and the fleet parked on the first.
 *
 * @return array{0: PlanetService, 1: PlanetService}
 */
function jumpGateMoonPair(int $playerId, int $firstPlanetId): array
{
    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    $second = Planet::factory()->create([
        'user_id' => $playerId,
        'galaxy' => 2,
        'system' => 2,
        'planet' => 2,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);

    $moonIds = [];
    foreach ([$firstPlanetId, $second->id] as $planetId) {
        $planet = app(PlanetServiceFactory::class)->makeForPlayer($player, $planetId, false);
        $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($planet, 2_000_000, 20);
        Planet::query()->whereKey($moon->getPlanetId())->update(['jump_gate' => 1]);
        $moonIds[] = $moon->getPlanetId();
    }

    // Reload the player so its planet list sees both new moons.
    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    Planet::query()->whereKey($moonIds[0])->update(['large_cargo' => 5]);

    return [
        app(PlanetServiceFactory::class)->makeForPlayer($player, $moonIds[0], true),
        app(PlanetServiceFactory::class)->makeForPlayer($player, $moonIds[1], true),
    ];
}

test('two gated moons make the save a jump instead of a flight', function (): void {
    jumpGateProfile($this->currentUserId);
    [$originMoon, $destinationMoon] = jumpGateMoonPair($this->currentUserId, $this->currentPlanetId);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan?->originPlanetId)->toBe($originMoon->getPlanetId())
        ->and($plan?->jumpGatePlanetId)->toBe($destinationMoon->getPlanetId());
});

test('a cooldown-blocked jump falls back to the flight', function (): void {
    jumpGateProfile($this->currentUserId);
    [$originMoon] = jumpGateMoonPair($this->currentUserId, $this->currentPlanetId);
    Planet::query()->whereKey($originMoon->getPlanetId())->update(['jump_gate_cooldown' => now()->addHour()->getTimestamp()]);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan->jumpGatePlanetId)->toBe(0);
});

test('the fleetsave action jumps the fleet between two gated moons', function (): void {
    jumpGateProfile($this->currentUserId);
    [$originMoon, $destinationMoon] = jumpGateMoonPair($this->currentUserId, $this->currentPlanetId);

    $result = app(QueueAiFleetSave::class)->handle(
        $this->currentUserId,
        $originMoon->getPlanetId(),
        $destinationMoon->getPlanetId(),
        0,
        0,
        0,
        0,
        1.0,
        $destinationMoon->getPlanetId(),
    );

    expect($result->successful)->toBeTrue($result->reason)
        ->and($result->reason)->toBe(AiQueueActionReason::JumpGateJumped->value)
        ->and(app(PlanetServiceFactory::class)->makeForPlayer(
            app(PlayerServiceFactory::class)->make($this->currentUserId, true),
            $destinationMoon->getPlanetId(),
            true,
        )->getObjectAmount('large_cargo'))->toBe(5);
});

test('a jump gate is not a chain step with one moon', function (): void {
    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $first = app(PlanetServiceFactory::class)->makeForPlayer($player, $this->currentPlanetId, false);
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($first, 2_000_000, 20);

    $names = array_map(
        static fn (BuildCandidate $candidate): string => ObjectService::getObjectById($candidate->buildingId)->machine_name,
        app(FacilityChain::class)->pending($moon),
    );

    expect($names)->not->toContain('jump_gate');
});

test('a jump gate is a chain step once the account owns two moons', function (): void {
    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $second = Planet::factory()->create([
        'user_id' => $this->currentUserId,
        'galaxy' => 2,
        'system' => 2,
        'planet' => 2,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);

    $firstMoon = null;
    foreach ([$this->currentPlanetId, $second->id] as $planetId) {
        $planet = app(PlanetServiceFactory::class)->makeForPlayer($player, $planetId, false);
        $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($planet, 2_000_000, 20);
        $firstMoon ??= $moon->getPlanetId();
    }

    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $firstMoonService = app(PlanetServiceFactory::class)->makeForPlayer($player, $firstMoon, true);

    $names = array_map(
        static fn (BuildCandidate $candidate): string => ObjectService::getObjectById($candidate->buildingId)->machine_name,
        app(FacilityChain::class)->pending($firstMoonService),
    );

    expect($names)->toContain('jump_gate');
});

dataset('staging enemies', [
    'an enemy beside the far moon' => [true, true],
    'a neutral beside the far moon' => [false, false],
]);

test('with no threat a fleet jumps to the gated moon nearest an enemy', function (bool $enemy, bool $jumps): void {
    jumpGateProfile($this->currentUserId);
    Planet::query()->whereKey($this->currentPlanetId)->update(['galaxy' => 5, 'system' => 100, 'planet' => 5]);
    [$originMoon, $forwardMoon] = jumpGateMoonPair($this->currentUserId, $this->currentPlanetId);
    $other = User::factory()->create();
    Planet::factory()->create(['user_id' => $other->id, 'galaxy' => 2, 'system' => 3, 'planet' => 4]);
    if ($enemy) {
        AiRelationship::unguarded(fn () => AiRelationship::create([
            'player_id' => $this->currentUserId,
            'other_player_id' => $other->id,
            'threat' => '1.0000',
            'trust' => '-1.0000',
            'revision' => 0,
        ]));
    }

    $plan = app(QueueableJumpGatePlanner::class)->plan($this->currentUserId);

    expect($plan instanceof QueueableJumpGate)->toBe($jumps);
    if ($jumps) {
        expect($plan->sourceMoonId)->toBe($originMoon->getPlanetId())
            ->and($plan->targetMoonId)->toBe($forwardMoon->getPlanetId());
    }
})->with('staging enemies');
