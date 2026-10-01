<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\DebrisField;
use OGame\Models\FleetMission;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ARB-001 at its boundary: debris beside the planet takes the session's one errand when a ship can
// harvest it, and is no errand at all when none can. The build queue fills in either case, which is
// what made the queue chore a wasted slot.

test('debris with a recycler wins the session and the build queue still fills', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('recycler', 2)
        ->debris(40_000, 20_000)
        ->session()
        ->expectWork(AiWorkKind::Recycle)
        ->expectWork(AiWorkKind::BuildFirstBuilding);
});

test('debris with no ship that can harvest it creates no recycle', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->debris(40_000, 20_000)
        ->session()
        ->expectNoWork(AiWorkKind::Recycle);
});
