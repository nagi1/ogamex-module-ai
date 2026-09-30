<?php

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The farming doctrine is data, so the test reads it the way the runtime does -- from the
 * module's resources/behavior directory -- rather than from a PHP constant that could
 * drift away from the file a modder edits.
 */
function ai_farm_policy_file(): string
{
    return dirname(__DIR__, 2) . '/resources/behavior/farm.yaml';
}

function ai_farm_policy(): string
{
    $path = ai_farm_policy_file();

    expect(is_file($path))->toBeTrue();

    return (string) file_get_contents($path);
}

/**
 * Every PHP source this module ships, so a policy value cannot quietly move back into code.
 *
 * @return list<string>
 */
function ai_farm_php_sources(): array
{
    $sources = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $sources[] = $file->getPathname();
    }

    return $sources;
}

it('routes the farm intent through the raid action', function () {
    $policy = ai_farm_policy();

    expect($policy)->toMatch('/^intent:\s*farm$/m');
    expect($policy)->toMatch('/^action:\s*raid$/m');
});

it('picks a farm target by inactivity and missing defences', function () {
    $policy = ai_farm_policy();

    expect($policy)->toMatch('/^\s+inactive_owner:\s*true$/m');
    expect($policy)->toMatch('/^\s+no_defences:\s*true$/m');
    expect($policy)->toMatch('/^\s+resources_on_planet:\s*true$/m');
});

it('turns a farm target down when the owner is defended or in an alliance', function () {
    $policy = ai_farm_policy();

    expect($policy)->toMatch('/^\s+alliance_member:\s*true$/m');
    expect($policy)->toMatch('/^\s+defended_target:\s*true$/m');
});

it('states no threshold of its own because the wiki states none', function () {
    expect(ai_farm_policy())->not->toMatch('/\d/');
});

it('keeps farm thresholds out of the PHP sources', function () {
    $offenders = [];

    foreach (ai_farm_php_sources() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/\bfarm\w*\s*(?:=|=>)\s*\d/i', $source) === 1) {
            $offenders[] = $path;
            continue;
        }

        if (preg_match('/\bfarm\w*(?:threshold|ratio|limit|cap|minimum|maximum)\w*\b/i', $source) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});
