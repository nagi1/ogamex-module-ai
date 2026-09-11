<?php

use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/Support/FixturePlayerPerceptionBuilder.php';
require_once __DIR__ . '/Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class)->in('Feature');
uses(IsolatedAccountTestCase::class)->in('Unit');

pest()->tia()->defaultBranch('main');
