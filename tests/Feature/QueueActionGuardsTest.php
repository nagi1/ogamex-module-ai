<?php

use Modules\AI\Actions\QueueAiColonyAction;
use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Actions\QueueAiRaidAction;
use Modules\AI\Actions\QueueAiSpyAction;
use Modules\AI\Actions\QueueAiUnitsAction;
use Modules\AI\Contracts\QueueAiColony;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Contracts\QueueAiSpy;
use Modules\AI\Contracts\QueueAiUnits;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Ban;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiColony::class, QueueAiColonyAction::class);
    app()->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
    app()->bind(QueueAiRaid::class, QueueAiRaidAction::class);
    app()->bind(QueueAiSpy::class, QueueAiSpyAction::class);
    app()->bind(QueueAiUnits::class, QueueAiUnitsAction::class);
});

test('a banned account is refused by every fleet and queue adapter', function (): void {
    guardProfile($this->currentUserId);
    Ban::create(['user_id' => $this->currentUserId, 'reason' => 'guard ban', 'banned_until' => now()->addHour(), 'canceled' => false]);

    $cargoId = ObjectService::getUnitObjectByMachineName('small_cargo')->id;
    $secondPlanetId = guardSecondPlanetId($this->currentUserId);

    expect(app(QueueAiUnits::class)->handle($this->currentUserId, $this->currentPlanetId, $cargoId, 1)->reason)->toBe(AiQueueActionReason::PlayerBanned->value)
        ->and(app(QueueAiColony::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 1, 4)->reason)->toBe(AiQueueActionReason::PlayerBanned->value)
        ->and(app(QueueAiFleetSave::class)->handle($this->currentUserId, $this->currentPlanetId, $secondPlanetId)->reason)->toBe(AiQueueActionReason::PlayerBanned->value)
        ->and(app(QueueAiSpy::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 1, 4, 1)->reason)->toBe(AiQueueActionReason::PlayerBanned->value)
        ->and(app(QueueAiRaid::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 1, 4, 1)->reason)->toBe(AiQueueActionReason::PlayerBanned->value);
});

test('a vacationing account is refused by every fleet and queue adapter', function (): void {
    guardProfile($this->currentUserId);
    User::query()->whereKey($this->currentUserId)->update(['vacation_mode' => true]);

    $cargoId = ObjectService::getUnitObjectByMachineName('small_cargo')->id;
    $secondPlanetId = guardSecondPlanetId($this->currentUserId);

    expect(app(QueueAiUnits::class)->handle($this->currentUserId, $this->currentPlanetId, $cargoId, 1)->reason)->toBe(AiQueueActionReason::VacationMode->value)
        ->and(app(QueueAiColony::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 1, 4)->reason)->toBe(AiQueueActionReason::VacationMode->value)
        ->and(app(QueueAiFleetSave::class)->handle($this->currentUserId, $this->currentPlanetId, $secondPlanetId)->reason)->toBe(AiQueueActionReason::VacationMode->value)
        ->and(app(QueueAiSpy::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 1, 4, 1)->reason)->toBe(AiQueueActionReason::VacationMode->value)
        ->and(app(QueueAiRaid::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 1, 4, 1)->reason)->toBe(AiQueueActionReason::VacationMode->value);
});

test('the units adapter validates its input and the unit kind', function (): void {
    guardProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));

    $cargoId = ObjectService::getUnitObjectByMachineName('small_cargo')->id;
    $buildingId = ObjectService::getObjectByMachineName('metal_mine')->id;

    expect(app(QueueAiUnits::class)->handle($this->currentUserId, $this->currentPlanetId, $cargoId, 0)->reason)->toBe(AiQueueActionReason::NothingQueueable->value)
        ->and(app(QueueAiUnits::class)->handle($this->currentUserId, 999_999_999, $cargoId, 1)->reason)->toBe(AiQueueActionReason::PlanetNotOwned->value)
        ->and(app(QueueAiUnits::class)->handle($this->currentUserId, $this->currentPlanetId, $buildingId, 1)->reason)->toBe(AiQueueActionReason::NotAUnit->value);
});

test('the units adapter queues a ship through the host unit queue', function (): void {
    guardProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('shipyard', 2);
    $this->playerSetResearchLevel('combustion_drive', 2);

    $cargoId = ObjectService::getUnitObjectByMachineName('small_cargo')->id;
    $result = app(QueueAiUnits::class)->handle($this->currentUserId, $this->currentPlanetId, $cargoId, 1);

    expect($result->successful)->toBeTrue($result->reason)
        ->and($result->queueId)->not->toBeNull();
});

test('a fleet or queue adapter refuses a planet it does not own', function (): void {
    guardProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $cargoId = ObjectService::getUnitObjectByMachineName('small_cargo')->id;
    $secondPlanetId = guardSecondPlanetId($this->currentUserId);

    expect(app(QueueAiColony::class)->handle($this->currentUserId, 999_999_999, 1, 1, 4)->reason)->toBe(AiQueueActionReason::PlanetNotOwned->value)
        ->and(app(QueueAiFleetSave::class)->handle($this->currentUserId, 999_999_999, $secondPlanetId)->reason)->toBe(AiQueueActionReason::PlanetNotOwned->value)
        ->and(app(QueueAiSpy::class)->handle($this->currentUserId, 999_999_999, 1, 1, 4, 1)->reason)->toBe(AiQueueActionReason::PlanetNotOwned->value)
        ->and(app(QueueAiUnits::class)->handle($this->currentUserId, 999_999_999, $cargoId, 1)->reason)->toBe(AiQueueActionReason::PlanetNotOwned->value);
});

test('an adapter reports a rejection when the host dispatch fails', function (): void {
    guardProfile($this->currentUserId);

    // An unknown unit id is thrown by the host object lookup before any queue write.
    $units = app(QueueAiUnits::class)->handle($this->currentUserId, $this->currentPlanetId, 999_999_999, 1);
    expect($units->successful)->toBeFalse();

    // A fleet action with a fleet but no deuterium is refused by the host mission start.
    $this->planetAddUnit('small_cargo', 1);
    $raid = app(QueueAiRaid::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 1, 4, 1);
    expect($raid->successful)->toBeFalse();

    $secondPlanetId = guardSecondPlanetId($this->currentUserId);
    $fleetSave = app(QueueAiFleetSave::class)->handle($this->currentUserId, $this->currentPlanetId, $secondPlanetId);
    expect($fleetSave->successful)->toBeFalse();

    // An espionage mission at an empty coordinate is refused by the host.
    $this->planetAddResources(new Resources(0, 0, 1_000_000));
    $this->planetAddUnit('espionage_probe', 1);
    $spy = app(QueueAiSpy::class)->handle($this->currentUserId, $this->currentPlanetId, 1, 1, 4, 1);
    expect($spy->successful)->toBeFalse();

    // A colonisation at an occupied coordinate is refused by the host.
    $foreign = $this->createForeignPlanet();
    $colony = app(QueueAiColony::class)->handle($this->currentUserId, $this->currentPlanetId, $foreign->getPlanetCoordinates()->galaxy, $foreign->getPlanetCoordinates()->system, $foreign->getPlanetCoordinates()->position);
    expect($colony->successful)->toBeFalse();
});

function guardProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 13_000 + $playerId,
        'enabled' => true,
    ]);
}

function guardSecondPlanetId(int $playerId): int
{
    $planets = app(\OGame\Factories\PlayerServiceFactory::class)->make($playerId, true)->planets->all();

    return $planets[1]->getPlanetId();
}
