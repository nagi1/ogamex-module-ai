### EDIT: app/Domain/Decision/DefenseNeedEvaluator.php
<<<<<<< SEARCH
 * production that accrues there while the account is away, both in the one currency the game prices
 * everything in, and that value is what the wall is sized to. The doctrine never enters here: it
 * picks the shape, not the size, so a Minimalist and a Bunker are handed the same size and build it
 * differently.
=======
 * production that accrues there while the account is away, both in the one currency the game prices
 * everything in, and that value is what the wall is sized to. The doctrine's shape never enters
 * here: a Minimalist and a Bunker are handed the same size and build it differently. The doctrine's
 * floor does, because a planet under it is worth the flight whatever it happens to hold.
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/DefenseNeedEvaluator.php
<<<<<<< SEARCH
        // What piles up before the account next looks at the planet, plus the pile itself once a
        // hostile is on its way and spending it is no longer an option.
        $exposure = $this->hourlyProduction($planet) * $this->absenceHours($profile->activity_band);
        if ($inbound) {
            $exposure += $this->metalEquivalent($planet->getResources());
        }

        // A wall already worth at least this much wants nothing further; the two planners agree on
        // the value through this comparison and nowhere else.
        if ($exposure <= $this->unitValue($planet->getDefenseUnits())) {
            return null;
        }

        return app()->makeWith(DefenseNeed::class, [
            'defenceValue' => $exposure,
            'reason' => 'defense:need:' . $this->contact($inbound),
        ]);
=======
        // What piles up before the account next looks at the planet, plus the pile itself once a
        // hostile is on its way and spending it is no longer an option.
        $exposure = $this->hourlyProduction($planet) * $this->absenceHours($profile->activity_band);
        if ($inbound) {
            $exposure += $this->metalEquivalent($planet->getResources());
        }

        // The doctrine's floor sits under every wall this account stands, so a planet nothing has
        // been seen near is still sized to it instead of being left bare.
        $target = max($exposure, $this->minimumDeterrent());

        // A wall already worth at least this much wants nothing further; the two planners agree on
        // the value through this comparison and nowhere else.
        if ($target <= $this->unitValue($planet->getDefenseUnits())) {
            return null;
        }

        return app()->makeWith(DefenseNeed::class, [
            'defenceValue' => $target,
            'reason' => 'defense:need:' . $this->contact($inbound),
        ]);
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/DefenseNeedEvaluator.php
<<<<<<< SEARCH
    private function absenceHours(?AiActivityBand $band): float
    {
        return self::HOURS_PER_DAY / max(1, $band?->value ?? AiActivityBand::Regular->value);
    }
=======
    private function absenceHours(?AiActivityBand $band): float
    {
        return self::HOURS_PER_DAY / max(1, $band?->value ?? AiActivityBand::Regular->value);
    }

    /**
     * The floor the doctrine puts under every wall, read from the doctrine file by key rather than
     * restated here: the doctrine owns its numbers, and a wall below this is the one thing the
     * account never leaves standing bare no matter what its own planets are exposed to.
     */
    private function minimumDeterrent(): float
    {
        $path = dirname(__DIR__, 3) . '/resources/behavior/defence-doctrines.yaml';
        if (! is_file($path)) {
            return 0.0;
        }

        // Read by key so the file can grow other doctrine blocks without moving this number.
        if (preg_match('/\bminimum_deterrent:\s*["\']?([0-9]+(?:\.[0-9]+)?)/', (string) file_get_contents($path), $match) !== 1) {
            return 0.0;
        }

        return (float) $match[1];
    }
>>>>>>> REPLACE

### FILE: tests/Feature/DefenseDeterrentFloorTest.php
```php
<?php

use Modules\AI\Domain\Decision\DefenseNeed;
use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

uses(AiQueueModuleTestCase::class);

/** The floor the doctrine file states, read here so the test fails when the file stops stating one. */
function statedMinimumDeterrent(): float
{
    $raw = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/behavior/defence-doctrines.yaml');
    preg_match('/\bminimum_deterrent:\s*["\']?([0-9]+(?:\.[0-9]+)?)/', $raw, $match);

    return (float) ($match[1] ?? 0.0);
}

/**
 * Sizes one naked planet of one account with no visitor inbound, so the wall it asks for comes from
 * exposure and the doctrine floor alone.
 */
function defenseNeedForPlanet(float $metalPerHour, AiActivityBand $band): ?DefenseNeed
{
    $user = User::factory()->create();

    AiProfile::create([
        'player_id' => $user->id,
        'activity_band' => $band,
    ]);

    $missions = Mockery::mock(FleetMissionService::class);
    $missions->shouldReceive('currentPlayerUnderAttack')->andReturn(false);
    app()->instance(FleetMissionService::class, $missions);

    $defense = Mockery::mock(UnitCollection::class);
    $defense->shouldReceive('toArray')->andReturn([]);

    $planet = Mockery::mock(PlanetService::class);
    $planet->shouldReceive('getMetalProductionPerHour')->andReturn($metalPerHour);
    $planet->shouldReceive('getCrystalProductionPerHour')->andReturn(0.0);
    $planet->shouldReceive('getDeuteriumProductionPerHour')->andReturn(0.0);
    $planet->shouldReceive('getDefenseUnits')->andReturn($defense);

    $player = Mockery::mock(PlayerService::class);
    $player->shouldReceive('getId')->andReturn($user->id);

    return app(DefenseNeedEvaluator::class)->evaluate($player, $planet);
}

test('a planet with nothing exposed at all is still sized to the doctrine floor', function () {
    $floor = statedMinimumDeterrent();

    expect($floor)->toBeGreaterThan(0.0);

    $need = defenseNeedForPlanet(0.0, AiActivityBand::Casual);

    expect($need)->not->toBeNull()
        ->and($need->defenceValue)->toBe($floor);
});

test('exposure under the floor is raised to the floor', function () {
    $floor = statedMinimumDeterrent();

    // A casual band looks in once a day, so a quarter of the floor an hour is a quarter of it a day.
    $need = defenseNeedForPlanet($floor / 96.0, AiActivityBand::Casual);

    expect($need?->defenceValue)->toBe($floor);
});

test('exposure past the floor sizes the wall and the floor no longer binds', function () {
    $floor = statedMinimumDeterrent();

    // A whole day's worth of the floor every hour: a day of exposure is twenty-four times the floor.
    $need = defenseNeedForPlanet($floor, AiActivityBand::Casual);

    expect($need?->defenceValue)->toBe(24.0 * $floor);
});
```

### FILE: resources/scenarios/defense-deterrent-floor.json
```json
{
    "name": "defense-deterrent-floor",
    "situation": "An account's planet has produced nothing since its last visit and no hostile fleet is on its way, so exposure alone asks for no wall at all; the doctrine still puts a floor under every wall it stands.",
    "status": "unverified",
    "persona": {
        "archetype": "miner",
        "activity_band": "casual"
    },
    "input": {
        "inbound": false,
        "metal_production_per_hour": 0,
        "crystal_production_per_hour": 0,
        "deuterium_production_per_hour": 0,
        "defense_value": 0
    },
    "decision_key": "defense_need",
    "expect": {
        "action": "queue_defense",
        "defence_value": 4000,
        "reason": "defense:need:unwatched"
    }
}
```

### FILE: resources/scenarios/defense-deterrent-floor-covered.json
```json
{
    "name": "defense-deterrent-floor-covered",
    "situation": "The same account's planet stands a wall already worth more than the doctrine floor and nothing has been seen near it, so there is nothing left for the doctrine to add.",
    "status": "unverified",
    "persona": {
        "archetype": "miner",
        "activity_band": "casual"
    },
    "input": {
        "inbound": false,
        "metal_production_per_hour": 0,
        "crystal_production_per_hour": 0,
        "deuterium_production_per_hour": 0,
        "defense_value": 24000
    },
    "decision_key": "defense_need",
    "expect": {
        "action": "none",
        "defence_value": 0,
        "reason": "wall:covered"
    }
}
```