<?php

use Modules\AI\Hooks\InstallModule;
use Modules\AI\Hooks\UninstallModule;
use Nwidart\Modules\Facades\Module;
use OGame\Modules\ModuleHookContext;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * @param  array<string, mixed>  $config
 * @return list<string>
 */
function aiInstallHookLines(array $config): array
{
    config($config);

    $lines = [];
    $context = app()->makeWith(ModuleHookContext::class, [
        'module' => Module::findOrFail('AI'),
        'dryRun' => false,
        'report' => function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    ]);

    app(InstallModule::class)->handle($context);

    return $lines;
}

test('the ai install hook reports horizon state for every configuration', function (): void {
    $warned = implode("\n", aiInstallHookLines(['queue.default' => 'database', 'cache.default' => 'array']));

    expect($warned)->toContain('Horizon lanes')
        ->toContain('QUEUE_CONNECTION is not "redis"')
        ->toContain('array cache store');

    $clean = implode("\n", aiInstallHookLines(['queue.default' => 'redis', 'cache.default' => 'file']));

    expect($clean)->toContain('Horizon lanes')
        ->not->toContain('QUEUE_CONNECTION is not')
        ->not->toContain('array cache store');
});

test('the ai uninstall hook explains what is retained', function (): void {
    $lines = [];
    $context = app()->makeWith(ModuleHookContext::class, [
        'module' => Module::findOrFail('AI'),
        'dryRun' => true,
        'report' => function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    ]);

    app(UninstallModule::class)->handle($context);

    expect(implode("\n", $lines))
        ->toContain('disabled')
        ->toContain('--drop-data');
});
