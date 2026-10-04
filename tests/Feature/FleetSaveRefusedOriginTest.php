<?php

use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// DISPATCH_REFUSALS: the fleet-save lane is the one dispatching planner that offered its body again
// after the gate refused it — a refused save is not a mission, so nothing read the missing hull or the
// short tank, and the next login offered the same body (the cohort's largest refusal, "no disposable
// fleet", at 32 in half an hour). The two calls the other dispatch planners already make are the fix:
// the origin the refusal blamed is skipped, at the bound (refused) and at zero (not refused).

test('a body the gate just refused a save from does not offer the save again', function (): void {
    $situation = Situation::of($this)
        ->colony()
        ->ships('large_cargo', 5);

    // Before the refusal the body's fleet is what the save moves, so the plan exists to be refused.
    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->not->toBeNull();

    $situation->refusedDispatch($this->currentPlanetId, reason: 'no_disposable_fleet');

    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->toBeNull();
});

// At zero: the same fleet with no refusal on record still saves, so the plant above is what stops it.
test('the same fleet saves when the gate refused nothing', function (): void {
    Situation::of($this)
        ->colony()
        ->ships('large_cargo', 5);

    expect(app(QueueableFleetSavePlanner::class)->plan($this->currentUserId))->not->toBeNull();
});
