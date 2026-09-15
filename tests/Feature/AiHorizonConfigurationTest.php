<?php

use Modules\AI\Providers\AIServiceProvider;
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
    // The path is redirected rather than moving the plan file: renaming a file that other
    // parallel workers read makes an unrelated Horizon test fail intermittently.
    config([
        'ai.horizon' => null,
        'ai.horizon_plan_path' => sys_get_temp_dir().'/ogamex-missing-horizon-'.uniqid().'.php',
        'horizon.environments' => ['production' => []],
    ]);
    // An enabled module has already contributed its own lanes at boot, so the claim under test is
    // "this call adds nothing", not "no such lane exists anywhere".
    $defaultsBefore = config('horizon.defaults');
    $environmentsBefore = config('horizon.environments');

    app(HorizonConfiguration::class)->contribute();

    expect(config('horizon.defaults'))->toEqual($defaultsBefore)
        ->and(config('horizon.environments'))->toEqual($environmentsBefore);
});

test('a partial plan file is read from the configured path', function (): void {
    $planFile = sys_get_temp_dir().'/ogamex-horizon-plan-'.uniqid().'.php';
    file_put_contents($planFile, "<?php\n\nreturn ['enabled' => true, 'supervisors' => ['supervisor-ai-tmp' => ['queue' => ['ai-tmp']]], 'processes' => ['supervisor-ai-tmp' => 2]];\n");

    config([
        'ai.horizon' => null,
        'ai.horizon_plan_path' => $planFile,
        'horizon.environments' => ['production' => []],
    ]);

    try {
        app(HorizonConfiguration::class)->contribute();

        expect(config('horizon.defaults.supervisor-ai-tmp.queue'))->toBe(['ai-tmp'])
            ->and(config('horizon.environments.production.supervisor-ai-tmp'))->toBe(['maxProcesses' => 2]);
    } finally {
        unlink($planFile);
    }
});

// The accelerated universe repeats the work pass every ten seconds instead of once a minute.
test('a fast session interval repeats the work pass every ten seconds', function (): void {
    config(['ai.population.session_interval_seconds' => 5]);

    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
    (new ReflectionMethod(AIServiceProvider::class, 'configureSchedules'))->invoke(new AIServiceProvider($this->app), $schedule);

    $events = collect($schedule->events())->filter(static fn ($event): bool => str_contains((string) $event->command, 'ai:run-due-work'));

    expect($events->isNotEmpty())->toBeTrue()
        ->and($events->contains(static fn ($event): bool => $event->isRepeatable()))->toBeTrue();
});
