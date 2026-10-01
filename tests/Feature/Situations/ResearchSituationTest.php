<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-006's fast proof (aspect: research). A player with a laboratory and stock keeps a research
// running: the lab is never left idle while the account can pay for the next level.
test('a funded account with a research lab starts a research', function (): void {
    Situation::of($this)
        ->level('research_lab', 3)
        ->resources(2_000_000, 2_000_000, 1_000_000)
        ->session()
        ->expectWork(AiWorkKind::QueueResearch);
});
