<?php

use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/Support/FixturePlayerPerceptionBuilder.php';
require_once __DIR__ . '/Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class)->in('Feature');
uses(IsolatedAccountTestCase::class)->in('Unit');

// This file is not the suite root for host runs: the host suite points at `Modules/*/tests/Feature`
// and `Modules/*/tests/Unit`, so hooks written here do not apply. Suite-wide guarantees belong
// where the base test case can make them -- the outbound HTTP client is fail-closed in
// `Tests\TestCase`, which is what keeps a run from reaching a real provider.

pest()->tia()->defaultBranch('main');
