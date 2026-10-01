<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\DebrisField;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// The kit's own proof: planted state in, the account's real session out, in about a second each. These
// double as the examples a writer copies, so every one reads as the story it proves.

test('a funded account with a free planet queues a building', function (): void {
    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectWork(AiWorkKind::BuildFirstBuilding);
});

test('an account with no debris and no ship creates no recycle', function (): void {
    DebrisField::query()->delete();

    Situation::of($this)->session()->expectNoWork(AiWorkKind::Recycle);
});
