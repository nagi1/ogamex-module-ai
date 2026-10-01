### EDIT: app/Domain/Decision/QueueableFleetSavePlanner.php
<<<<<<< SEARCH
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
=======
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\BehaviorValues;
use Modules\AI\Support\RandomSource;
>>>>>>> REPLACE

<<<<<<< SEARCH
    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private QueueableRecyclePlanner $queueableRecyclePlanner,
        private JumpGateService $jumpGate,
    ) {
    }
=======
    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private QueueableRecyclePlanner $queueableRecyclePlanner,
        private JumpGateService $jumpGate,
        private BehaviorValues $behavior,
    ) {
    }
>>>>>>> REPLACE

<<<<<<< SEARCH
        // The reactive save (an inbound hostile) is not a matter of taste: a save under
        // attack always wins, so it uses the base band. Aggression only moves the proactive
        // save below, where the account chooses how much fleet it is willing to risk.
        return $this->saveFor($player, $planets, $profile->archetype);
=======
        // The reactive save (an inbound hostile) is not a matter of taste: a save under
        // attack always wins, so no exposure band gates it (V6). Aggression only moves the
        // proactive save below, where the account chooses how much fleet it is willing to risk.
        return $this->saveFor($player, $planets, $profile->archetype);
>>>>>>> REPLACE

<<<<<<< SEARCH
        $origin = $this->origin($planets);
        if ($origin === null || $this->fleetValue($origin) < $this->exposureBand($archetype, $aggression)) {
            return null;
        }
=======
        $inbound = $this->inboundHostiles($player);

        $origin = $this->origin($planets, $inbound);
        if ($origin === null) {
            return null;
        }

        // An inbound hostile forces the save (V6), so the band that decides ordinary cadence
        // — how much fleet this persona is willing to risk unsaved — does not gate it.
        if ($inbound === [] && $this->fleetValue($origin) < $this->exposureBand($archetype, $aggression)) {
            return null;
        }
>>>>>>> REPLACE

<<<<<<< SEARCH
    /**
     * The first planet carrying a movable fleet.
     *
     * @param array<int, PlanetService> $planets
     */
    private function origin(array $planets): ?PlanetService
    {
        foreach ($planets as $planet) {
            if ($planet->getShipUnits()->units !== []) {
                return $planet;
            }
        }

        return null;
    }
=======
    /**
     * The body the save leaves: a planet an inbound hostile is already flying to
     * and that carries ships comes first, so the threatened fleet is the one that
     * moves; otherwise the first planet carrying a movable fleet.
     *
     * @param array<int, PlanetService> $planets
     * @param array<int, int> $inbound planet id => arrival timestamp
     */
    private function origin(array $planets, array $inbound): ?PlanetService
    {
        $fallback = null;
        foreach ($planets as $planet) {
            if ($planet->getShipUnits()->units === []) {
                continue;
            }

            if (isset($inbound[$planet->getPlanetId()])) {
                return $planet;
            }

            $fallback ??= $planet;
        }

        return $fallback;
    }
>>>>>>> REPLACE

<<<<<<< SEARCH
        $unsafe = $this->unsafeDestinations($player);

        $destinations = array_values(array_filter(
=======
        $unsafe = $this->inboundHostiles($player);

        $destinations = array_values(array_filter(
>>>>>>> REPLACE

<<<<<<< SEARCH
        $unsafe = $this->unsafeDestinations($player);

        foreach ($this->jumpGate->getEligibleTargets($player, $origin) as $target) {
=======
        $unsafe = $this->inboundHostiles($player);

        foreach ($this->jumpGate->getEligibleTargets($player, $origin) as $target) {
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
     * The host's real inbound-hostile picture: fleets another account still has in
     * flight to one of this account's own bodies, keyed by the body they arrive at
     * with their earliest arrival. It is the one authority for "under attack" —
     * an inbound hostile is owned by the attacker, never by this account, so this
     * account's own active missions cannot answer it (V6, FS-010).
     *
     * @return array<int, int> planet id => arrival timestamp
     */
    private function inboundHostiles(PlayerService $player): array
    {
        $destinations = array_map(
            static fn (PlanetService $planet): int => $planet->getPlanetId(),
            $player->planets->all(),
        );

        $missionTypes = (array) ($this->behavior->load('fleet-save-reaction')['hostile_mission_types'] ?? []);
        if ($destinations === [] || $missionTypes === []) {
            return [];
        }

        $threats = [];
        $missions = FleetMission::query()
            ->where('user_id', '!=', $player->getId())
            ->whereIn('planet_id_to', $destinations)
            ->whereIn('mission_type', array_map(static fn (mixed $type): int => (int) $type, $missionTypes))
            ->where('canceled', 0)
            ->where('processed', 0)
            ->orderBy('time_arrival')
            ->get();

        foreach ($missions as $mission) {
            $threats[(int) $mission->planet_id_to] ??= (int) $mission->time_arrival;
        }

        return $threats;
    }
>>>>>>> REPLACE

### FILE: app/Support/BehaviorValues.php
```php
<?php

namespace Modules\AI\Support;

/**
 * Loads a behaviour file by name from the module's resources/behavior directory.
 *
 * The files hold flat keys with scalar or inline-list values only, so a reader
 * that needs neither the yaml extension nor a parser package is enough; a real
 * YAML reader is used when the application already ships one.
 */
class BehaviorValues
{
    /**
     * @return array<string, mixed>
     */
    public function load(string $name): array
    {
        $path = dirname(__DIR__, 2) . '/resources/behavior/' . $name . '.yaml';
        if (!is_file($path)) {
            return [];
        }

        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return (array) \Symfony\Component\Yaml\Yaml::parseFile($path);
        }

        return $this->parseFlatFile($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFlatFile(string $path): array
    {
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $pair = explode(':', explode('#', $line, 2)[0], 2);
            if (count($pair) < 2) {
                continue;
            }

            $values[trim($pair[0])] = $this->parseValue(trim($pair[1]));
        }

        return $values;
    }

    private function parseValue(string $raw): mixed
    {
        if (!str_starts_with($raw, '[') || !str_ends_with($raw, ']')) {
            return is_numeric($raw) ? $raw + 0 : trim($raw, '"\'');
        }

        $items = array_filter(
            array_map(static fn (string $item): string => trim($item), explode(',', substr($raw, 1, -1))),
            static fn (string $item): bool => $item !== '',
        );

        return array_values(array_map(fn (string $item): mixed => $this->parseValue($item), $items));
    }
}
```

### FILE: resources/behavior/fleet-save-reaction.yaml
```yaml
# What the reactive fleet save reads (V6, FLEET-003): a fleet another account
# still has in flight to one of this account's own bodies whose mission type is
# listed below is the inbound hostile that forces a save, and the body it arrives
# at is never a save destination. The ids are the host's mission types: attack,
# group (ACS) attack, moon destruction.
hostile_mission_types: [1, 2, 9]
```

### FILE: resources/scenarios/inbound-attack.json
```json
{
    "name": "inbound-attack",
    "situation": "A hostile fleet is on its way to the account's fleet planet: mission type attack, still processed = 0, arrival inside the reaction lead the account itself uses. Nothing else about the account changes.",
    "persona": "fleeter",
    "input": {
        "inbound_fleet": {
            "owner": "foreign",
            "mission_type": 1,
            "target": "cohort_planet",
            "arrival_seconds": 300,
            "ships": {
                "light_fighter": 40,
                "cruiser": 15,
                "small_cargo": 20
            }
        }
    },
    "decision_key": "fleet_save",
    "status": "unverified",
    "expect": {
        "action": "fleet_save",
        "description": "the account moves its fleet off the threatened body to another own body instead of continuing to build and research"
    },
    "checklist": [
        {
            "topic": "threat-source",
            "question": "Which host table and columns carry a hostile fleet that another account has in flight to one of this account's bodies?",
            "status": "verified"
        },
        {
            "topic": "hostile-mission-types",
            "question": "Which mission types count as hostile when they arrive at an own body?",
            "status": "verified"
        },
        {
            "topic": "force-size",
            "question": "Does an inbound hostile force a save of a fleet smaller than the persona's own exposure band?",
            "status": "verified"
        },
        {
            "topic": "destination-safety",
            "question": "Is a body a hostile is already inbound to ever chosen as the save destination?",
            "status": "verified"
        }
    ]
}
```

### FILE: resources/scenarios/no-inbound-attack.json
```json
{
    "name": "no-inbound-attack",
    "situation": "The same account with the same small fleet and no foreign fleet inbound to any of its bodies. Nothing forces a save, so the persona's own exposure band stands and the fleet stays where it is.",
    "persona": "fleeter",
    "input": {},
    "decision_key": "fleet_save",
    "status": "unverified",
    "expect": {
        "action": null,
        "description": "no fleet save work item is planned: without an inbound hostile the exposure band decides"
    },
    "checklist": [
        {
            "topic": "cadence",
            "question": "With no inbound hostile and a fleet below the exposure band, does the account plan no save at all?",
            "status": "verified"
        },
        {
            "topic": "non-hostile-inbound",
            "question": "Does a foreign fleet on a non-hostile mission inbound to an own body leave the exposure band in place?",
            "status": "verified"
        }
    ]
}
```

### FILE: tests/Feature/InboundHostileFleetSaveTest.php
```php
<?php

use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Support\BehaviorValues;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The hostile mission type the module's own behaviour file names, so the test
 * does not carry the host's mission ids itself.
 */
function inboundAttackMissionType(): int
{
    return (int) app(BehaviorValues::class)->load('fleet-save-reaction')['hostile_mission_types'][0];
}

/**
 * A fleet another account has in flight, exactly as the host stores it.
 */
function plantInboundFleetMission(int $attackerId, int $originPlanetId, int $targetPlanetId, int $missionType, int $processed = 0): void
{
    FleetMission::create([
        'user_id' => $attackerId,
        'planet_id_from' => $originPlanetId,
        'planet_id_to' => $targetPlanetId,
        'mission_type' => $missionType,
        'time_departure' => now()->timestamp,
        'time_arrival' => now()->timestamp + 300,
        'processed' => $processed,
        'canceled' => 0,
    ]);
}

function foreignAttackerId(Planet $planet): int
{
    return (int) Planet::query()->whereKey($planet->getPlanetId())->value('user_id');
}

test('an inbound hostile forces the fleet save the exposure band refuses', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

    // One small cargo is below every persona's exposure band, so ordinary cadence
    // plans nothing at all.
    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();

    // A visible hostile — owned by the attacker, still processed = 0 — is the
    // reactive save's source (V6).
    $foreign = $this->createForeignPlanet();
    plantInboundFleetMission(
        foreignAttackerId($foreign),
        $foreign->getPlanetId(),
        $this->currentPlanetId,
        inboundAttackMissionType(),
    );

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->originPlanetId)->toBe($this->currentPlanetId)
        ->and($plan->destinationPlanetId)->not->toBe($this->currentPlanetId);
});

test('a foreign fleet that is not hostile, and a hostile the host already processed, force nothing', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

    $foreign = $this->createForeignPlanet();
    $attackerId = foreignAttackerId($foreign);

    // A transport is a foreign fleet inbound to an own body, but it threatens nothing.
    plantInboundFleetMission($attackerId, $foreign->getPlanetId(), $this->currentPlanetId, 3);

    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();

    // A hostile the host has processed has already landed; it is not inbound any more.
    plantInboundFleetMission($attackerId, $foreign->getPlanetId(), $this->currentPlanetId, inboundAttackMissionType(), 1);

    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('a hostile inbound to a body without ships still forces the swap off the fleet planet', function (): void {
    fleetProfile($this->currentUserId);
    $this->planetAddUnit('small_cargo', 1);

    $foreign = $this->createForeignPlanet();
    $ownBodies = array_values(array_diff(fleetOwnPlanetIds($this->currentUserId), [$this->currentPlanetId]));
    plantInboundFleetMission(foreignAttackerId($foreign), $foreign->getPlanetId(), $ownBodies[0], inboundAttackMissionType());

    $plan = app(QueueableFleetSavePlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableFleetSave::class)
        ->and($plan->destinationPlanetId)->not->toBe($ownBodies[0]);
});
```