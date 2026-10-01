<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ARB-001's other side. With nothing to raid, recycle or colonise, an established Miner orders ships
// now and then, not on every login: grand ordered units in most sessions once the errand slot stopped
// going to Build (`ogamex pulse`, 1 Oct 2026: 262 unit orders against 411 buildings in 30 minutes), which
// is the shipyard lottery behind WALL_CEILING again.
test('an established miner with nothing to farm does not order ships on every login', function (): void {
    $situation = Situation::of($this)
        ->level('shipyard', 4)
        ->level('robot_factory', 2)
        ->research('combustion_drive', 6)
        ->resources(5_000_000, 3_000_000, 1_000_000)
        ->sessions(6);

    $orders = count(array_filter($situation->work(), static fn (AiWorkKind $kind): bool => $kind === AiWorkKind::QueueUnits));

    expect($orders)->toBeLessThanOrEqual(3, 'ordered ships on ' . $orders . ' of 6 logins; ' . $situation->account());
});
