### EDIT: app/Domain/Decision/QueueableFleetSavePlanner.php
<<<<<<< SEARCH
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\DeploymentMission;
=======
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\DeploymentMission;
>>>>>>> REPLACE

<<<<<<< SEARCH
        $player ??= $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();

        // The reactive save (an inbound hostile) is not a matter of taste: a save under
        // attack always wins, so it uses the base band. Aggression only moves the proactive
        // save below, where the account chooses how much fleet it is willing to risk.
        return $this->saveFor($player, $planets, $profile->archetype);
    }
=======
        $player ??= $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();

        // The reactive save is not a matter of taste: a hostile fleet is already flying at
        // one of this account's bodies, so the fleet that body carries leaves now — the
        // exposure band is skipped, since a fleet under attack is saved at any size (V6).
        // Aggression only moves the proactive save below, where the account chooses how
        // much fleet it is willing to risk.
        $threatened = $this->threatenedFleetPlanet($player, $planets);
        if ($threatened !== null) {
            return $this->saveFrom($player, $planets, $threatened, $profile->archetype);
        }

        return $this->saveFor($player, $planets, $profile->archetype);
    }
>>>>>>> REPLACE

<<<<<<< SEARCH
    private function saveFor(PlayerService $player, array $planets, AiArchetype $archetype, float $aggression = 0.5, ?int $absenceMinutes = null): ?QueueableFleetSave
    {
        $origin = $this->origin($planets);
        if ($origin === null || $this->fleetValue($origin) < $this->exposureBand($archetype, $aggression)) {
            return null;
        }

        // A save moves ships; a planet holding only solar satellites has
=======
    private function saveFor(PlayerService $player, array $planets, AiArchetype $archetype, float $aggression = 0.5, ?int $absenceMinutes = null): ?QueueableFleetSave
    {
        $origin = $this->origin($planets);
        if ($origin === null || $this->fleetValue($origin) < $this->exposureBand($archetype, $aggression)) {
            return null;
        }

        return $this->saveFrom($player, $planets, $origin, $archetype, $aggression, $absenceMinutes);
    }

    /**
     * The save from a body already chosen: a jump gate when one is free, otherwise the
     * safest own destination, otherwise the debris-field fallback. Shared by the reactive
     * save an inbound hostile forces and the proactive absence save, so both move the
     * fleet the same way.
     *
     * @param array<int, PlanetService> $planets
     */
    private function saveFrom(PlayerService $player, array $planets, PlanetService $origin, AiArchetype $archetype, float $aggression = 0.5, ?int $absenceMinutes = null): ?QueueableFleetSave
    {
        // A save moves ships; a planet holding only solar satellites has
>>>>>>> REPLACE

<<<<<<< SEARCH
    /**
     * The first own body a hostile fleet is already inbound to. Parking the save on one
     * of them is worse than holding, so they are not destinations (FS-010). The
     * same active-mission source the inbound picture already reads, so this is
     * the one authority for "under attack".
     *
     * @return array<int, true>
     */
    private function unsafeDestinations(PlayerService $player): array
    {
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $unsafe = [];
        foreach ($fleetMissions->getActiveFleetMissionsForCurrentPlayer() as $mission) {
            if ($mission->user_id !== $player->getId()) {
                $unsafe[(int) $mission->planet_id_to] = true;
            }
        }

        return $unsafe;
    }
=======
    /**
     * The own bodies a hostile fleet is already inbound to. Parking the save on one of
     * them is worse than holding, so they are not destinations (FS-010). The same
     * inbound picture the reactive save reads, so this is the one authority for
     * "under attack".
     *
     * @return array<int, true>
     */
    private function unsafeDestinations(PlayerService $player): array
    {
        return $this->inboundHostilePlanetIds($player, $player->planets->all());
    }

    /**
     * The own body whose fleet has to leave now: a hostile fleet is inbound to it and it
     * carries something movable (V6).
     *
     * @param array<int, PlanetService> $planets
     */
    private function threatenedFleetPlanet(PlayerService $player, array $planets): ?PlanetService
    {
        $inbound = $this->inboundHostilePlanetIds($player, $planets);

        foreach ($planets as $planet) {
            if (!isset($inbound[$planet->getPlanetId()])) {
                continue;
            }

            if (MovableFleet::of($player, $planet->getShipUnits())->units === []) {
                continue;
            }

            return $planet;
        }

        return null;
    }

    /**
     * The account's inbound hostile picture, as planet id to true. An inbound hostile is
     * owned by the attacker, so the host's player-scoped active-mission list never shows
     * it and the mission rows are read directly instead: the attack mission type, not yet
     * processed, not canceled, still on its way to a body this account owns.
     *
     * @param array<int, PlanetService> $planets
     * @return array<int, true>
     */
    private function inboundHostilePlanetIds(PlayerService $player, array $planets): array
    {
        $ownPlanetIds = [];
        foreach ($planets as $planet) {
            $ownPlanetIds[] = $planet->getPlanetId();
        }

        if ($ownPlanetIds === []) {
            return [];
        }

        $arrivals = FleetMission::query()
            ->whereIn('planet_id_to', $ownPlanetIds)
            ->where('user_id', '!=', $player->getId())
            ->where('mission_type', AttackMission::getTypeId())
            ->where('processed', 0)
            ->where('canceled', 0)
            ->where('time_arrival', '>=', now()->timestamp)
            ->pluck('planet_id_to');

        $hostile = [];
        foreach ($arrivals as $planetId) {
            $hostile[(int) $planetId] = true;
        }

        return $hostile;
    }
>>>>>>> REPLACE

### FILE: resources/scenarios/inbound-hostile-forces-fleet-save.json
```json
{
    "name": "inbound-hostile-forces-fleet-save",
    "situation": "Another player's attack is already inbound to the account's only fleet planet, arriving inside the account's reaction lead. The fleet parked there is a single small cargo, smaller than the persona's exposure band, so nothing but the attack makes the account move it.",
    "status": "verified",
    "persona": {
        "archetype": "Trader"
    },
    "input": {
        "inbound_hostiles": [
            {
                "mission_type": 1,
                "planet_id_to": "own fleet planet",
                "processed": 0,
                "canceled": 0,
                "seconds_to_arrival": 300
            }
        ],
        "units": {
            "small_cargo": 1
        }
    },
    "decision_key": "fleet_save",
    "expect": {
        "action": "FleetSave",
        "reason_contains": "hostile"
    }
}
```

### FILE: resources/scenarios/no-inbound-hostile-leaves-small-fleet-alone.json
```json
{
    "name": "no-inbound-hostile-leaves-small-fleet-alone",
    "situation": "The account's only fleet is a single small cargo on its home planet, below the persona's exposure band, and no hostile fleet is inbound to any body the account owns: the save must not fire and the account keeps building its economy.",
    "status": "verified",
    "persona": {
        "archetype": "Trader"
    },
    "input": {
        "inbound_hostiles": [],
        "units": {
            "small_cargo": 1
        }
    },
    "decision_key": "fleet_save",
    "expect": {
        "action": "Build"
    }
}
```

### FILE: tests/Feature/InboundHostileFleetSaveTest.php
```php
<?php

use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(AiQueueModuleTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
});

/**
 * An own planet other than the origin: a destination a real deployment can fly to, so
 * the mission row the fixture re-points is created by the host's own save action and no
 * fleet_missions column is invented here.
 */
function inboundHostileOtherOwnPlanetId(int $playerId, int $originPlanetId): int
{
    $player = app(PlayerServiceFactory::class)->make($playerId, true);

    foreach ($player->planets->all() as $planet) {
        if ($planet->getPlanetId() !== $originPlanetId) {
            return $planet->getPlanetId();
        }
    }

    return 0;
}

/**
 * The planted attack: the mission the account's own save action created is handed to
 * another account and re-pointed as the attack mission type arriving at
 * $targetPlanetId, still unprocessed.
 */
function inboundHostilePlantAttack(IsolatedAccountTestCase $case, int $missionId, int $targetPlanetId): void
{
    $foreign = $case->createForeignPlanet();

    FleetMission::query()->whereKey($missionId)->update([
        'user_id' => (int) Planet::query()->whereKey($foreign->getPlanetId())->value('user_id'),
        'planet_id_from' => $foreign->getPlanetId(),
        'planet_id_to' => $targetPlanetId,
        'mission_type' => AttackMission::getTypeId(),
        'processed' => 0,
        'canceled' => 0,
        'time_arrival' => now()->timestamp + 60,
    ]);
}

test('an inbound hostile forces the save the exposure band would refuse', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $this->planetAddUnit('small_cargo', 1);

    $destinationPlanetId = inboundHostileOtherOwnPlanetId($this->currentUserId, $this->currentPlanetId);
    $deployment = app(QueueAiFleetSave::class)->handle($this->currentUserId, $this->currentPlanetId, $destinationPlanetId);
    expect($deployment->successful)->toBeTrue($deployment->reason);

    // One small cargo is below every persona's band, and the only mission on the map is
    // the account's own flight — an own flight is not an inbound hostile.
    $this->planetAddUnit('small_cargo', 1);
    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();

    inboundHostilePlantAttack($this, $deployment->queueId, $this->currentPlanetId);

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->originPlanetId)->toBe($this->currentPlanetId)
        ->and($plan->destinationPlanetId)->not->toBe($this->currentPlanetId);
});

test('an inbound hostile already resolved or canceled forces nothing', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $this->planetAddUnit('small_cargo', 1);

    $destinationPlanetId = inboundHostileOtherOwnPlanetId($this->currentUserId, $this->currentPlanetId);
    $deployment = app(QueueAiFleetSave::class)->handle($this->currentUserId, $this->currentPlanetId, $destinationPlanetId);
    expect($deployment->successful)->toBeTrue($deployment->reason);

    $this->planetAddUnit('small_cargo', 1);
    inboundHostilePlantAttack($this, $deployment->queueId, $this->currentPlanetId);

    FleetMission::query()->whereKey($deployment->queueId)->update(['processed' => 1]);
    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();

    FleetMission::query()->whereKey($deployment->queueId)->update(['processed' => 0, 'canceled' => 1]);
    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('an inbound hostile to a body with no fleet forces nothing', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $this->planetAddUnit('small_cargo', 1);

    $destinationPlanetId = inboundHostileOtherOwnPlanetId($this->currentUserId, $this->currentPlanetId);
    $deployment = app(QueueAiFleetSave::class)->handle($this->currentUserId, $this->currentPlanetId, $destinationPlanetId);
    expect($deployment->successful)->toBeTrue($deployment->reason);

    inboundHostilePlantAttack($this, $deployment->queueId, $this->currentPlanetId);

    // The attack arrives at a body whose fleet already left, so there is nothing to move.
    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();
});
```