<?php

use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// AUTH_UPTIME: the host counts the hours of the day an account is active, and an account whose logins
// cover eighteen or more of them never sleeps. A hostile fleet landing in the account's own night is the
// one event that used to pull it back online then, so the situation is planted -- an attack due well
// inside the night, a fleet to lose, the clock at the last look before bed -- and the whole day played:
// the account logs in after its wake and not once while it sleeps.
test('an account attacked in its night sleeps through it and logs in after its wake', function (): void {
    $situation = Situation::of($this)
        ->resources(50_000, 50_000, 50_000)
        ->ships('large_cargo', 5)
        ->beforeBed(5)
        ->hostileFleet(2_400)
        ->day(24);

    $profile = AiProfile::query()->where('player_id', $this->currentUserId)->sole();
    $planner = app(SessionPlanner::class);
    $logins = AiWorkItem::query()->where('player_id', $this->currentUserId)
        ->where('kind', AiWorkKind::RunSession)
        ->where('state', AiWorkState::Completed)
        ->orderBy('id')->get();

    $night = $logins->filter(static fn (AiWorkItem $login): bool => !$planner->isAwake($profile, $login->updated_at->toImmutable()))
        ->map(static fn (AiWorkItem $login): string => $login->updated_at->toIso8601String())
        ->values()->all();

    // A day of play, none of it inside the night: the wake after the attack is the account's own.
    expect($logins->count())->toBeGreaterThan(1)
        ->and($night)->toBe([], 'the account logged in at night: ' . $situation->account());
});
