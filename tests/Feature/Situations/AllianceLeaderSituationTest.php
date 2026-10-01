<?php

use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ALLY-001's fast proof (aspect: alliance). An alliance leader answers an application within the hour,
// accept or refuse; it is never left pending.
test('an application to the account\'s alliance is decided after the leader\'s waiting time', function (): void {
    Situation::of($this)
        ->allianceApplication(minutesAgo: 30)
        ->minute()
        ->expectApplicationDecided();
});
