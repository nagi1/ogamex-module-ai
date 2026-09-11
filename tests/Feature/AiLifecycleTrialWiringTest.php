<?php

use Symfony\Component\Process\Process;

/**
 * The module's shell entry points are what developers actually run, and the host trial
 * is what proves the module's whole life against the live stack. This guards the wiring
 * between them: valid syntax, every documented runner command, and the identifiers the
 * host trial looks for actually matching what the module ships.
 */
function moduleRootPath(): string
{
    return dirname(__DIR__, 2);
}

function hostRootPath(): string
{
    return dirname(moduleRootPath(), 2);
}

function moduleScriptPath(string $relative): string
{
    return moduleRootPath().'/'.$relative;
}

function hostTrialPath(): string
{
    return hostRootPath().'/scripts/e2e-module-install-trial.sh';
}

function moduleScriptSyntaxIsValid(string $path): void
{
    expect($path)->toBeFile();

    $process = new Process(['bash', '-n', $path], hostRootPath());
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toBe('');
}

test('the module runner and the host trial are valid shell scripts', function (): void {
    moduleScriptSyntaxIsValid(moduleScriptPath('scripts/ogamex'));
    moduleScriptSyntaxIsValid(hostTrialPath());
});

test('the module runner exposes the documented commands', function (string $command): void {
    expect(file_get_contents(moduleScriptPath('scripts/ogamex')))->toContain("    {$command})");
})->with([
    'artisan',
    'install',
    'uninstall',
    'doctor',
    'enable',
    'disable',
    'test',
    'test-all',
    'quality',
    'coverage',
    'e2e-lifecycle',
]);

test('the runner forwards e2e-lifecycle to the host trial', function (): void {
    $runner = file_get_contents(moduleScriptPath('scripts/ogamex'));

    expect($runner)->toContain('MODULE=AI bash scripts/e2e-module-install-trial.sh')
        ->and(hostTrialPath())->toBeFile();
});

test('the container fragment declares the pool the host trial looks for', function (): void {
    $fragment = file_get_contents(moduleScriptPath('docker/supervisor/queue-worker.conf'));

    expect($fragment)->toContain('[program:ai-queue-worker]')
        ->and($fragment)->toContain('--queue=ai,ai-language');
});

test('the host trial defaults point at this module wiring', function (string $needle): void {
    expect(file_get_contents(hostTrialPath()))->toContain($needle);
})->with([
    'ai-queue-worker',
    'supervisor-ai',
    'ai_',
]);
