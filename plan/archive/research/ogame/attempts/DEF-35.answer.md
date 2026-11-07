### EDIT: app/Domain/Decision/QueueableSpyPlanner.php
<<<<<<< SEARCH
            if ($owner?->getUsername(false) === 'Legor') {
                continue;
            }
=======
            if ($owner?->isAdmin() === true) {
                continue;
            }
>>>>>>> REPLACE

### FILE: tests/Feature/SpyTargetAuthorityTest.php
```php
<?php

use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\GameMissions\EspionageMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The gate the host owns: the protected administrator, not whoever the operator
// happens to have called "Legor".
test('the spy planner probes a neighbour called Legor when the host does not protect them', function (): void {
    spyAuthorityProfile($this->currentUserId);
    $owner = spyAuthorityTarget($this->currentUserId, 'Legor');
    spyAuthorityPlanet($this->currentPlanetId, $owner->id, 2, 1, 8);
    $this->planetAddUnit(EspionageMission::getRequiredShipMachineNames()[0], 1);

    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableSpy::class)
        ->and($plan?->targetGalaxy)->toBe(2)
        ->and($plan?->targetSystem)->toBe(1)
        ->and($plan?->targetPosition)->toBe(8);
});

test('the spy planner plans nothing when the account has no probe on hand', function (): void {
    spyAuthorityProfile($this->currentUserId);
    $owner = spyAuthorityTarget($this->currentUserId, 'Legor');
    spyAuthorityPlanet($this->currentPlanetId, $owner->id, 2, 1, 8);

    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId))->toBeNull();
});

function spyAuthorityProfile(int $playerId): AiProfile
{
    $profile = AiProfile::query()->firstOrCreate(
        ['player_id' => $playerId],
        [
            'archetype' => AiArchetype::cases()[0]->value,
            'skill_band' => AiSkillBand::cases()[0]->value,
            'enabled' => true,
        ],
    );

    $profile->update(['enabled' => true]);

    return $profile;
}

/**
 * A second account, copied from the one under test so every NOT NULL column the
 * host requires is carried over. Its clock is pushed back so the neighbour is not
 * read as currently active.
 */
function spyAuthorityTarget(int $sourceUserId, string $username): User
{
    $source = User::query()->findOrFail($sourceUserId);

    $target = $source->replicate();
    $target->username = $username;
    $target->email = $username . '-' . uniqid() . '@example.test';
    $target->created_at = now()->subDays(7);
    $target->updated_at = now()->subDays(3);
    $target->save();

    return $target;
}

function spyAuthorityPlanet(int $sourcePlanetId, int $userId, int $galaxy, int $system, int $position): Planet
{
    $source = Planet::query()->findOrFail($sourcePlanetId);

    $planet = $source->replicate();
    $planet->user_id = $userId;
    $planet->galaxy = $galaxy;
    $planet->system = $system;
    $planet->planet = $position;
    $planet->created_at = now()->subDays(7);
    $planet->updated_at = now()->subDays(3);
    $planet->save();

    return $planet;
}
```

### FILE: resources/scenarios/spy-target-authority.json
```json
{
    "name": "spy-target-authority",
    "persona": "An AI account that scouts its neighbourhood before raiding and never spends a probe on a planet the host protects.",
    "situation": "A neighbour account carries the operator's old name but the host does not mark it as the protected administrator; the account under test holds an idle probe.",
    "status": "unverified",
    "checklist": [
        {
            "topic": "protected-administrator",
            "question": "Which host signal marks the protected administrator, and is it the account's own flag rather than its username?",
            "status": "unverified"
        },
        {
            "topic": "probe-requirement",
            "question": "Which ship must the host's espionage mission carry, and does the account hold one idle?",
            "status": "unverified"
        },
        {
            "topic": "activity-gate",
            "question": "Which signal makes the module treat a neighbour as active and leave it alone?",
            "status": "unverified"
        }
    ],
    "input": {
        "player": "the account under test",
        "probe_ship": "the ship the host's espionage mission requires",
        "neighbour": {
            "username": "Legor",
            "owner_protected_by_host": false
        }
    },
    "decision_key": "spy_target_authority",
    "expect": {
        "work_kind": "Spy",
        "action": "DispatchFleet",
        "target": "the neighbour whose owner the host does not protect"
    }
}
```

### FILE: resources/scenarios/spy-target-protected.json
```json
{
    "name": "spy-target-protected",
    "persona": "An AI account that scouts its neighbourhood before raiding and never spends a probe on a planet the host protects.",
    "situation": "The only foreign planet in reach belongs to the host's protected administrator; the account under test holds an idle probe.",
    "status": "unverified",
    "checklist": [
        {
            "topic": "protected-administrator",
            "question": "Does the account leave the host's protected administrator alone whatever the operator named them?",
            "status": "unverified"
        },
        {
            "topic": "probe-requirement",
            "question": "Is the probe still held after a decision that produced no work?",
            "status": "unverified"
        },
        {
            "topic": "alternative-target",
            "question": "Is there any other neighbour the account may probe instead, so that skipping the protected one is not the same as planning nothing?",
            "status": "unverified"
        }
    ],
    "input": {
        "player": "the account under test",
        "probe_ship": "the ship the host's espionage mission requires",
        "neighbour": {
            "username": "the administrator's own name",
            "owner_protected_by_host": true
        }
    },
    "decision_key": "spy_target_authority",
    "expect": {
        "work_kind": "none",
        "action": "none",
        "reason": "the only neighbour in reach belongs to the host's protected administrator"
    }
}
```