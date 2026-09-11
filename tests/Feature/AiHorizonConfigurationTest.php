<?php

use Modules\AI\Support\HorizonConfiguration;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * A minimal module plan so these tests exercise the wiring without depending on
 * the shipped supervisor values.
 *
 * @return array<string, mixed>
 */
function horizonTestPlan(): array
{
    return [
        'enabled' => true,
        'supervisors' => ['supervisor-ai-test' => ['queue' => ['ai-test'], 'tries' => 1, 'timeout' => 5]],
        'processes' => ['supervisor-ai-test' => 3],
        'waits' => ['ai-test' => 60],
    ];
}

test('the module plan is applied to the host horizon configuration', function (): void {
    config(['ai.horizon' => horizonTestPlan(), 'horizon.environments' => ['production' => []]]);

    app(HorizonConfiguration::class)->contribute();

    expect(config('horizon.defaults.supervisor-ai-test.queue'))->toBe(['ai-test'])
        ->and(config('horizon.waits.redis:ai-test'))->toBe(60)
        ->and(config('horizon.environments.production.supervisor-ai-test'))->toBe(['maxProcesses' => 3]);
});

test('a disabled plan leaves the host horizon configuration untouched', function (): void {
    config([
        'ai.horizon' => ['enabled' => false] + horizonTestPlan(),
        'horizon.environments' => ['production' => []],
    ]);

    app(HorizonConfiguration::class)->contribute();

    expect(config('horizon.defaults.supervisor-ai-test'))->toBeNull()
        ->and(config('horizon.environments.production.supervisor-ai-test'))->toBeNull();
});

test('a host without horizon is left alone', function (): void {
    config(['ai.horizon' => horizonTestPlan(), 'horizon' => null]);

    app(HorizonConfiguration::class)->contribute();

    expect(config('horizon'))->toBeNull();
});

test('environment provisioning is skipped when the host has no environment blocks', function (): void {
    config(['ai.horizon' => horizonTestPlan(), 'horizon.environments' => null]);

    app(HorizonConfiguration::class)->contribute();

    expect(config('horizon.defaults.supervisor-ai-test.queue'))->toBe(['ai-test'])
        ->and(config('horizon.environments'))->toBeNull();
});

test('a stale config cache falls back to the module plan file', function (): void {
    config(['ai.horizon' => null, 'horizon.environments' => ['production' => []]]);

    app(HorizonConfiguration::class)->contribute();

    expect(config('horizon.defaults.supervisor-ai.queue'))->toBe(['ai'])
        ->and(config('horizon.defaults.supervisor-ai-language.queue'))->toBe(['ai-language'])
        ->and(config('horizon.environments.production.supervisor-ai'))->toBe(['maxProcesses' => 1]);
});

test('a missing plan file registers no lanes instead of failing the boot', function (): void {
    $planFile = dirname(__DIR__, 2).'/config/horizon.php';
    $backup = $planFile.'.test-backup';

    expect(is_file($planFile))->toBeTrue();
    rename($planFile, $backup);

    try {
        config(['ai.horizon' => null, 'horizon.environments' => ['production' => []]]);

        app(HorizonConfiguration::class)->contribute();

        expect(config('horizon.defaults.supervisor-ai'))->toBeNull()
            ->and(config('horizon.environments.production.supervisor-ai'))->toBeNull();
    } finally {
        rename($backup, $planFile);
    }
});
