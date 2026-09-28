<?php

use Modules\AI\Domain\Decision\DefenseNeed;
use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\GameMissions\AttackMission;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The defence need is what the planet stands to lose, not what class it belongs to: the production
 * that piles up before the account looks in again, plus the pile itself once a hostile is inbound and
 * spending it is too late. The account's own activity band sets the first term and the archetype sets
 * neither, which is the whole point of the slice.
 */
beforeEach(function (): void {
    $this->planetSetObjectLevel('metal_mine', 10);
    $this->planetSetObjectLevel('crystal_mine', 8);
    $this->planetSetObjectLevel('deuterium_synthesizer', 6);
    $this->planetSetObjectLevel('solar_plant', 14);
    $this->planetService->updateResourceProductionStats();
});

/** The planet's own output per hour, in the one currency the module prices everything in. */
function defenseHourly(PlanetService $planet): float
{
    return $planet->getMetalProductionPerHour()
        + 1.5 * $planet->getCrystalProductionPerHour()
        + 2.0 * $planet->getDeuteriumProductionPerHour();
}

/** A pile of some hours' output, so the production term stays the smaller of the two. */
function defensePile(PlanetService $planet, float $hours): void
{
    $planet->addResources(new Resources((int) round($hours * defenseHourly($planet)), 0, 0));
}

function defenseNeedProfile(int $playerId, AiActivityBand $band, AiArchetype $archetype = AiArchetype::Miner): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'activity_band' => $band,
        'random_seed' => 4_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A foreign fleet inbound at this account's planet, so the host reports it under attack. */
function defenseInboundHostile(int $attackerPlayerId, int $planetId): void
{
    $mission = new FleetMission();
    $mission->user_id = $attackerPlayerId;
    $mission->planet_id_from = null;
    $mission->planet_id_to = $planetId;
    $mission->mission_type = AttackMission::getTypeId();
    $mission->time_departure = now()->subMinute()->timestamp;
    $mission->time_arrival = now()->addHour()->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();
}

// The band is how often the account looks in: a regular one leaves twelve hours of output standing,
// a hardcore one six, so the same planet and pile want a smaller wall.
test('a rarely-present band needs a bigger wall than one that is around more', function (): void {
    defenseNeedProfile($this->currentUserId, AiActivityBand::Regular);

    $regular = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    AiProfile::query()->where('player_id', $this->currentUserId)->update(['activity_band' => AiActivityBand::Hardcore->value]);

    $hardcore = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    expect($regular)->toBeInstanceOf(DefenseNeed::class)
        ->and($hardcore)->toBeInstanceOf(DefenseNeed::class)
        ->and($regular->reason)->toBe('defense:need:unwatched')
        ->and($regular->defenceValue)->toBeGreaterThan($hardcore->defenceValue);
});

// The archetype says how the account expects to grow, not how exposed it is: two accounts with the
// same band, planet and pile want exactly the same wall.
test('the need comes from exposure, not from the archetype label', function (): void {
    defenseNeedProfile($this->currentUserId, AiActivityBand::Regular, AiArchetype::Miner);
    defensePile($this->planetService, 4);

    $miner = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    AiProfile::query()->where('player_id', $this->currentUserId)->update(['archetype' => AiArchetype::Fleeter->value]);

    $fleeter = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    expect($miner)->toBeInstanceOf(DefenseNeed::class)
        ->and($fleeter)->toBeInstanceOf(DefenseNeed::class)
        ->and($fleeter->defenceValue)->toEqualWithDelta($miner->defenceValue, 0.0);
});

// An unmolested account spends its surplus (D10), so a big pile is not by itself a reason to build a
// wall of its own size; only the production that keeps arriving is at risk.
test('a pile nobody is coming for does not demand a wall of its own size', function (): void {
    defenseNeedProfile($this->currentUserId, AiActivityBand::Casual);
    defensePile($this->planetService, 100);

    $needed = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    expect($needed)->toBeInstanceOf(DefenseNeed::class)
        ->and($needed->defenceValue)->toEqualWithDelta(24 * defenseHourly($this->planetService), 0.5)
        ->and($needed->defenceValue)->toBeLessThan(100 * defenseHourly($this->planetService));
});

// Once the host says a hostile is inbound there is no time left to spend the pile, so the whole pile
// joins what the wall must cover.
test('an inbound hostile makes the pile part of what the wall must cover', function (): void {
    defenseNeedProfile($this->currentUserId, AiActivityBand::Regular);
    defensePile($this->planetService, 100);

    $unwatched = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    defenseInboundHostile($this->createForeignPlanet()->getPlayer()->getId(), $this->currentPlanetId);

    $inbound = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    expect($unwatched)->toBeInstanceOf(DefenseNeed::class)
        ->and($inbound)->toBeInstanceOf(DefenseNeed::class)
        ->and($inbound->reason)->toBe('defense:need:inbound')
        ->and($inbound->defenceValue)->toBeGreaterThan($unwatched->defenceValue);
});

test('a wall already worth what the planet stands to lose needs nothing further', function (): void {
    defenseNeedProfile($this->currentUserId, AiActivityBand::Regular);
    defensePile($this->planetService, 4);
    $this->planetAddUnit('light_laser', 100_000);

    expect(app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService))->toBeNull();
});

test('an account with no persona has no need to state', function (): void {
    expect(app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService))->toBeNull();
});

test('the same planet and band always state the same need', function (): void {
    defenseNeedProfile($this->currentUserId, AiActivityBand::Regular);
    defensePile($this->planetService, 4);

    $first = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);
    $second = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    expect($first)->toBeInstanceOf(DefenseNeed::class)
        ->and($second)->toBeInstanceOf(DefenseNeed::class)
        ->and($first->defenceValue)->toEqualWithDelta($second->defenceValue, 0.0)
        ->and($first->reason)->toBe($second->reason);
});
