<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// DISPATCH_REFUSALS: the host answers a second expedition with "You are conducting too many expeditions at
// the same time", and the decision that produced it was legal when the login made it — the account's one
// slot filled while the worker was still behind (the live cohort carried 13 pending Expedition intents,
// measured 4 Oct 2026: one body refused for this five times in six hours). The slot is the account's, so it
// is asked again where the count is live, before anything is written: the account backs off instead of
// offering the game a flight it has to refuse.

test('the second decided expedition does not fly into the account own one slot', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 1)
        ->research('combustion_drive', 6)
        ->ships('small_cargo', 2)
        ->resources(0, 0, 500_000)
        ->decidedExpedition()
        ->decidedExpedition()
        ->session();

    // Astrophysics 1 grants floor(sqrt(1)) = 1 slot: the first decision flies, the second is answered by the
    // account itself, so the host's own sentence never appears.
    expect($situation->missions())->toBe(['Expedition'])
        ->and(implode(' | ', $situation->refused()))->toContain('source_short_at_dispatch')
        ->and(implode(' | ', $situation->refused()))->not->toContain('too many expeditions');
});

// At zero: the same decision with a free slot is the flight the account decided on, so asking the slot again
// at dispatch does not stand the lane down.
test('a decided expedition flies when the account holds a free slot', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 1)
        ->research('combustion_drive', 6)
        ->ships('small_cargo', 1)
        ->resources(0, 0, 500_000)
        ->decidedExpedition()
        ->session();

    expect($situation->missions())->toBe(['Expedition'])
        ->and(implode(' | ', $situation->refused()))->not->toContain('too many expeditions');
});

// A decision the session made before the gate refused the account's own dispatch orders still sits
// queued: the executor answers it from the same account-lane memory the planner reads, so the host's
// own sentence is written once at most instead of once per queued decision.
test('a decided expedition is answered by the account own lane, not by the host', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 4)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 10)
        ->resources(0, 0, 500_000)
        ->decidedExpedition();

    $situation->refusedDispatch($this->currentPlanetId, reason: 'You are conducting too many expeditions at the same time.');
    $situation->session();

    $refused = $situation->refused();

    expect($situation->missions())->toBe([])
        ->and(implode(' | ', $refused))->toContain('source_short_at_dispatch')
        // The planted refusal is the only receipt carrying the host's own sentence: the queued decision
        // was answered by the account's memory, not by asking the host a second time.
        ->and(count(array_filter($refused, static fn (string $line): bool => str_contains($line, 'too many expeditions'))))->toBe(1);
});
