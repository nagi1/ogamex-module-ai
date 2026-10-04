<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// DISPATCH_REFUSALS: a decision is written before it flies and the worker may run it hours later, so a
// refusal the gate raised in between is one no planner could have read. Measured 4 Oct 2026: one body
// answered with the same refusal three times in six hours, once per decision that had been queued before
// the first of them. Two decisions written for the expedition lane the gate refused while they waited is
// the story: the account already holds that answer, so it offers neither of them again.

// At zero: nothing was refused, so the decision that is waiting is the errand the account takes.
test('a queued decision flies when the gate refused nothing', function (): void {
    Situation::of($this)
        ->research('astrophysics', 4)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 10)
        ->resources(0, 0, 500_000)
        ->decidedExpedition()
        ->session()
        ->expectMission('Expedition');
});

// At the bound: the lane was refused while both decisions sat queued, so neither is offered again and the
// account writes no refusal of its own.
test('a decision already queued for a refused lane is dropped, not refused again', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 4)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 10)
        ->resources(0, 0, 500_000)
        ->decidedExpedition()
        ->decidedExpedition();

    $situation->refusedDispatch($this->currentPlanetId, reason: 'source_short_at_dispatch');
    $situation->session();

    $refusals = array_filter(
        $situation->refused(),
        static fn (string $line): bool => str_contains($line, 'source_short_at_dispatch'),
    );

    expect($situation->missions())->toBe([])
        ->and($refusals)->toHaveCount(1, 'the refusal the gate made once is the whole memory');
});

// Past the bound: the cooling a launched mission buys has run out, so the decision that waited is the
// errand the account takes, as a player who has waited for the slot tries again.
test('a decision still queued flies once the refusal has cooled', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 4)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 10)
        ->resources(0, 0, 500_000)
        ->decidedExpedition();

    $situation->refusedDispatch($this->currentPlanetId, reason: 'source_short_at_dispatch', minutesAgo: 720);
    $situation->session();

    $situation->expectMission('Expedition');
});
