### EDIT: app/Domain/Decision/RaidPlanner.php
<<<<<<< SEARCH
    /** The host's hard bashing limit: at most six attacks on one target per day. */
    private const BASHING_LIMIT = 6;
=======
    /**
     * The one statement of the attack cap: at most six attacks on one planet in
     * a day, the bashing rule every universe runs on. The host exposes no surface
     * for it — FleetController only ever reports the limit as unreached — so a
     * universe that tunes it diverges, and the cap is therefore stated here once
     * and read by name (RaidPlanner::BASHING_LIMIT) wherever else it is needed.
     * Two other copies of this cap once carried a different number.
     */
    public const BASHING_LIMIT = 6;
>>>>>>> REPLACE

### FILE: tests/Feature/RaidBashingCapTest.php
```php
<?php

use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\GameMissions\AttackMission;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// RaidPlanner::BASHING_LIMIT is the module's single statement of the attack cap:
// six attacks on one planet inside a day. The history below is read by the
// planner's own bashing check, so the bound, just under it and well past it are
// the three readings that matter.

test('a raid flies when the planet has not been attacked today', function (): void {
    $this->planetAddResources(new Resources(2_000_000, 2_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);

    $raid = raidBashingFixture($this->currentUserId, $this->currentPlanetId);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $raid['reportId']))->not->toBeNull();
});

test('a raid still flies on the last attack the cap allows', function (): void {
    $this->planetAddResources(new Resources(2_000_000, 2_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);

    $raid = raidBashingFixture($this->currentUserId, $this->currentPlanetId);
    raidBashingAttacks($this->currentUserId, $raid['originPlanetId'], $raid['targetPlanetId'], RaidPlanner::BASHING_LIMIT - 1);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $raid['reportId']))->not->toBeNull();
});

test('the attack that would break the cap is refused', function (): void {
    $this->planetAddResources(new Resources(2_000_000, 2_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);

    $raid = raidBashingFixture($this->currentUserId, $this->currentPlanetId);
    raidBashingAttacks($this->currentUserId, $raid['originPlanetId'], $raid['targetPlanetId'], RaidPlanner::BASHING_LIMIT);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $raid['reportId']))->toBeNull();
});

test('a target attacked far past the cap stays refused', function (): void {
    $this->planetAddResources(new Resources(2_000_000, 2_000_000, 1_000_000));
    $this->planetAddUnit('small_cargo', 1);

    $raid = raidBashingFixture($this->currentUserId, $this->currentPlanetId);
    raidBashingAttacks($this->currentUserId, $raid['originPlanetId'], $raid['targetPlanetId'], RaidPlanner::BASHING_LIMIT + 3);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $raid['reportId']))->toBeNull();
});

/**
 * The account side of a raid decision: an enabled profile, a colony so the account
 * is past the opening phase (which is what makes a fleet-less target raid-able),
 * and a defenceless farm the report points at. The farm is placed a few systems
 * from the account's own planet so the flight stays cheap enough to clear the
 * loot-to-fuel tier on its own.
 *
 * @return array{reportId: int, targetPlanetId: int, originPlanetId: int}
 */
function raidBashingFixture(int $playerId, int $originPlanetId): array
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::cases()[0],
        'skill_band' => AiSkillBand::cases()[0],
        'enabled' => true,
        'random_seed' => 1,
    ]);

    $origin = Planet::query()->findOrFail($originPlanetId);

    Planet::factory()->create([
        'user_id' => $playerId,
        'galaxy' => $origin->galaxy === 1 ? 2 : 1,
        'system' => 300,
        'planet' => 4,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);

    $farmUser = User::factory()->create();
    $farm = Planet::factory()->create([
        'user_id' => $farmUser->id,
        'galaxy' => $origin->galaxy,
        'system' => $origin->system >= 400 ? $origin->system - 4 : $origin->system + 4,
        'planet' => 9,
        'metal' => 500_000,
        'crystal' => 500_000,
        'deuterium' => 250_000,
        'time_last_update' => now()->subHour()->getTimestamp(),
    ]);

    $report = EspionageReport::create([
        'user_id' => $playerId,
        'planet_user_id' => $farmUser->id,
        'planet_galaxy' => $farm->galaxy,
        'planet_system' => $farm->system,
        'planet_position' => $farm->planet,
        'planet_type' => 1,
        'metal' => 500_000,
        'crystal' => 500_000,
        'deuterium' => 250_000,
        'ships' => [],
        'defense' => [],
    ]);

    return [
        'reportId' => $report->id,
        'targetPlanetId' => $farm->id,
        'originPlanetId' => $originPlanetId,
    ];
}

/**
 * Attacks the account already flew at the planet, stamped outside the raid
 * cooldown so only the bashing check can be the reason a later raid is refused.
 */
function raidBashingAttacks(int $playerId, int $originPlanetId, int $targetPlanetId, int $count): void
{
    $origin = Planet::query()->findOrFail($originPlanetId);
    $target = Planet::query()->findOrFail($targetPlanetId);

    for ($attack = 0; $attack < $count; $attack++) {
        FleetMission::create([
            'user_id' => $playerId,
            'mission_type' => AttackMission::getTypeId(),
            'planet_id_from' => $originPlanetId,
            'planet_id_to' => $targetPlanetId,
            'galaxy_from' => $origin->galaxy,
            'system_from' => $origin->system,
            'planet_from' => $origin->planet,
            'galaxy_to' => $target->galaxy,
            'system_to' => $target->system,
            'planet_to' => $target->planet,
            'time_departure' => now()->subHours(8)->timestamp,
            'time_arrival' => now()->subHours(7)->timestamp,
        ]);
    }
}
```

### FILE: resources/scenarios/raid-attack-cap-within.json
```json
{
    "name": "raid-attack-cap-within",
    "situation": "A fleeter holds a fresh report on a defenceless farm and has already attacked that planet five times in the last day — the last attack the cap allows.",
    "status": "unverified",
    "persona": "fleeter",
    "input": {
        "attacks_on_target_last_24h": 5,
        "hours_since_last_attack": 8,
        "target": {
            "defenceless": true,
            "loot": {
                "metal": 500000,
                "crystal": 500000,
                "deuterium": 250000
            }
        },
        "origin_fleet": {
            "small_cargo": 1
        }
    },
    "decision_key": "raid",
    "expect": {
        "action": "Raid"
    }
}
```

### FILE: resources/scenarios/raid-attack-cap-exceeded.json
```json
{
    "name": "raid-attack-cap-exceeded",
    "situation": "The same fleeter holds the same fresh report, but the planet has already been attacked six times in the last day, so the cap is spent.",
    "status": "unverified",
    "persona": "fleeter",
    "input": {
        "attacks_on_target_last_24h": 6,
        "hours_since_last_attack": 8,
        "target": {
            "defenceless": true,
            "loot": {
                "metal": 500000,
                "crystal": 500000,
                "deuterium": 250000
            }
        },
        "origin_fleet": {
            "small_cargo": 1
        }
    },
    "decision_key": "raid",
    "expect": {
        "action": null,
        "reason": "bashing_limit"
    }
}
```