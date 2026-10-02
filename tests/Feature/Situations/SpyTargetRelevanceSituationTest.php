<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ATK-001's relevance rule at its floor: the choice of neighbour is only reachable when the account
// has a probe to send, so an account with none creates no spy work however good the farm next door is.
test('an account with no probe on hand spies nothing even beside a rich inactive neighbour', function (): void {
    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->inactiveNeighbour(daysQuiet: 8)
        ->session()
        ->expectNoWork(AiWorkKind::Spy);
});
