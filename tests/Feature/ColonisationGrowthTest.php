<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-008's boundary. The colony ship is what the mission consumes, but the host gates the
// destination on the account's own astrophysics: a ship without the reach finds no colonisable
// slot, so the account must not dispatch it. The kit plants no research, so the reach is zero.
test('a colony ship without the astrophysics reach flies no colonisation', function (): void {
    Situation::of($this)
        ->ships('colony_ship', 1)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->sessions(2)
        ->expectNoMission('Colonisation');
});

// A free slot and no colony ship the yard can take: the dispatch would only be refused, and it was, 201 times in one hour
// of the grand universe (8 Nov 2026). The account waits for the yard instead of writing a colonisation it cannot fly.
test('no colony ship and none the yard can take writes no colonisation', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 4)
        ->research('impulse_drive', 3)
        ->resources(0, 0, 50_000)
        ->session();

    expect(array_filter($situation->refused(), static fn (string $refusal): bool => str_contains($refusal, 'colony_ship')))->toBe([]);
    $situation->expectNoWork(AiWorkKind::Colonize);
});
