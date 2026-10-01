<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// QUAL-008's fast proof (aspect: colonisation). A player who has the astrophysics level for another
// planet and a colony ship on hand sends it: an unused colony slot is lost growth.
test('a colony slot free and a colony ship on hand is a colonisation the account flies', function (): void {
    Situation::of($this)
        ->research('astrophysics', 4)
        ->research('impulse_drive', 3)
        ->ships('colony_ship', 1)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->sessions(2)
        ->expectWork(AiWorkKind::Colonize)
        ->expectMission('Colonisation');
});
