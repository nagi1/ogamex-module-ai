<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ATK-001's fast proof. A neighbour who has not logged in for eight days and holds stock is what an
// experienced player farms: they look (a probe), then raid. The session must start that, with probes and
// cargo on hand, in a universe with other players in it (crowd). A neighbour who logged in yesterday
// is not farmed.
test('an inactive neighbour with stock is looked at or raided by an account that has the ships', function (): void {
    $situation = Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('espionage_probe', 5)
        ->ships('small_cargo', 20)
        ->crowd(25)
        ->inactiveNeighbour(daysQuiet: 8)
        ->session();

    $started = array_filter($situation->work(), static fn (AiWorkKind $kind): bool => in_array($kind, [AiWorkKind::Spy, AiWorkKind::Raid], true));

    expect($started)->not->toBe([], $situation->account());
});

test('a neighbour who logged in yesterday is not raided', function (): void {
    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('espionage_probe', 5)
        ->ships('small_cargo', 20)
        ->inactiveNeighbour(daysQuiet: 1)
        ->session()
        ->expectNoWork(AiWorkKind::Raid);
});
