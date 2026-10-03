<?php

use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\FleetMission;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// AUTH_UPTIME: the host flags a player whose logins span eighteen or more distinct hours of the day, and
// the routine already fills most of them, so a single session in the night is the whole difference. The
// attack that lands there is what used to put one there: a player at the keyboard answers an attack coming
// in, one who is asleep reads that report in the morning. Both stories run the account's own session path.

test('an attack arriving in the account night puts no login in it', function (): void {
    $situation = Situation::of($this)
        ->ships('large_cargo', 5)
        ->beforeBed(5)
        ->hostileFleet(3_600)
        ->day(24);

    $profile = AiProfile::query()->where('player_id', $this->currentUserId)->sole();
    $planner = app(SessionPlanner::class);
    $logins = AiWorkItem::query()->where('player_id', $this->currentUserId)
        ->where('kind', AiWorkKind::RunSession)->orderBy('id')->get();
    $night = $logins
        ->filter(static fn (AiWorkItem $login): bool => !$planner->isAwake($profile, $login->due_at->toImmutable()))
        ->map(static fn (AiWorkItem $login): string => $login->due_at->toIso8601String())->values()->all();

    expect($logins)->not->toBeEmpty()
        ->and($night)->toBe([], 'the account scheduled a login in its night: ' . $situation->account());
});

test('the look at that attack is left to the next waking day', function (): void {
    $situation = Situation::of($this)
        ->ships('large_cargo', 5)
        ->beforeBed(5)
        ->hostileFleet(3_600)
        ->session();

    $arrival = (int) FleetMission::query()->where('user_id', '!=', $this->currentUserId)->where('mission_type', 1)->max('time_arrival');
    $login = AiWorkItem::query()->where('player_id', $this->currentUserId)
        ->where('kind', AiWorkKind::RunSession)->where('state', AiWorkState::Pending)->orderByDesc('id')->firstOrFail();

    expect($login->due_at->getTimestamp())->toBeGreaterThan($arrival + 3_600, 'the account took the look at night: ' . $situation->account());
});
