<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// SIM-001's fast proof (situation: inbound-message, aspect: social). Someone writes to the account; a
// player answers on their next login.
test('a direct message is answered on the next login', function (): void {
    Situation::of($this)
        ->directMessage('hey, are you active? want to trade?')
        ->minutesLater(40)
        ->session()
        ->expectReply();
});
