<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\DebrisField;
use OGame\Models\FleetMission;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// FLEET-002's fast proof. A player with debris beside the planet and recyclers on hand sends them; the
// session must choose it. Today the account picks "build" every time (measured with the Situation kit,
// 1 Oct 2026): a recycle is never the highest-scoring candidate, so no account recycles.
test('debris beside the planet and recyclers on hand is a recycle the account sends', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('recycler', 2)
        ->debris(40_000, 20_000)
        ->session()
        ->expectWork(AiWorkKind::Recycle);
});
