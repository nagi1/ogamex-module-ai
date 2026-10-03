<?php

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// AUTH_UPTIME: the host counts the hours of the day an account is active, and one active in eighteen or
// more of them never sleeps. An accelerated cohort asks for a login every few seconds, so the worker is
// always minutes behind and every session it reaches is overdue; an overdue session used to be claimed at
// any hour, which put the whole population online through its own night. A login keeps the hour it was
// scheduled for: the one the routine placed in the dark period -- the wake the account takes for an attack
// landing then -- is still taken then, and a login it owed its day waits for its next waking day.

test('a login the worker is late for waits for the account\'s next waking day', function (): void {
    $profile = lateLoginProfile($this->currentUserId);
    // The account's own last half hour of play, whatever hour a CI machine runs at.
    $this->travelTo(lateLoginBed($profile)->subMinutes(30));
    // A login it was due to take 55 minutes before bed and no worker reached: the backlog a cohort with
    // more accounts than workers leaves behind is exactly this.
    $login = lateLoginWorkItem($this->currentUserId, 25);
    $this->travel(40)->minutes();

    app()->makeWith(ProcessAiWork::class, ['workItemId' => $login->id])->handle();

    expect($login->fresh()?->state)->toBe(AiWorkState::Pending);
});

test('the login the routine placed in the night is the one the account takes then', function (): void {
    $profile = lateLoginProfile($this->currentUserId);
    $this->travelTo(lateLoginBed($profile)->addMinutes(5));
    // Due now, inside the dark period: the wake the account scheduled for itself there.
    $login = lateLoginWorkItem($this->currentUserId);

    app()->makeWith(ProcessAiWork::class, ['workItemId' => $login->id])->handle();

    expect($login->fresh()?->state)->toBe(AiWorkState::Completed);
});

function lateLoginProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 20_007,
        'enabled' => true,
    ]);
}

/**
 * The first minute of the account's own night: step into its waking day from wherever the clock is, then
 * forward to the minute it goes to sleep. A story about the night must not depend on the hour the suite
 * happens to run at.
 */
function lateLoginBed(AiProfile $profile): CarbonImmutable
{
    $planner = app(SessionPlanner::class);
    $instant = now()->toImmutable();

    for ($step = 0; $step < 2880 && !$planner->isAwake($profile, $instant); $step++) {
        $instant = $instant->addMinute();
    }

    for ($step = 0; $step < 2880 && $planner->isAwake($profile, $instant); $step++) {
        $instant = $instant->addMinute();
    }

    return $instant;
}

/** A login of the account's own that no worker has run yet: due $minutesDueAgo ago at the clock the test set. */
function lateLoginWorkItem(int $playerId, int $minutesDueAgo = 0): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $playerId,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now()->subMinutes($minutesDueAgo),
        'idempotency_key' => 'late-login:' . $minutesDueAgo . ':' . uniqid(),
        'state' => AiWorkState::Pending,
    ]);
}
