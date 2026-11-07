<?php

use Modules\Empire\Services\DiplomacyService;
use Symfony\Component\Yaml\Yaml;

// One notice length for the simulator, the AI module and the Empire tab: the Empire constant follows social-minds.yaml.
test('the Empire pact notice equals the social minds pact_notice_hours', function () {
    $minds = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/social-minds.yaml');

    expect(DiplomacyService::NOTICE_HOURS)->toBe((int) $minds['pact_notice_hours']);
});
