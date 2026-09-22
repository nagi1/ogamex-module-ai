<?php

use Modules\AI\Actions\BuildAiSettingsPanelAction;
use Modules\AI\Support\AiSettings;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * @return array{live: list<array<string, mixed>>, deployment: string, services: list<array<string, mixed>>, up: ?string, down: ?string}
 */
function deployPanelFor(string $yaml): array
{
    $file = tempnam(sys_get_temp_dir(), 'ai-settings-');
    file_put_contents($file, $yaml);
    app()->instance(AiSettings::class, AiSettings::resolve($file));
    $panel = app(BuildAiSettingsPanelAction::class)->handle();
    unlink($file);

    return $panel;
}

test('the up command names only the services the file selects', function (): void {
    $panel = deployPanelFor("drivers:\n  cognition: fatima\n  mode: hybrid\n  memory: native\n  experience: native\n");

    expect($panel['up'])->toContain('up -d fatima')
        ->not->toContain('psychsim')
        ->not->toContain('agentos')
        ->not->toContain('cbrkit');
});

test('the down command stops the services the file no longer needs', function (): void {
    $panel = deployPanelFor("drivers:\n  cognition: native\n  mode: native\n  memory: native\n  experience: native\n");

    expect($panel['up'])->toBeNull()
        ->and($panel['down'])->toContain('stop fatima')
        ->toContain('psychsim')
        ->toContain('agentos')
        ->toContain('cbrkit');
});

test('native mode keeps every sidecar not needed', function (): void {
    $panel = deployPanelFor("drivers:\n  cognition: fatima\n  mode: native\n  memory: agentos\n  experience: cbrkit\n");

    expect(collect($panel['services'])->pluck('needed')->all())->toBe([false, false, false, false]);
});

test('the matrix flags the selected services and the commands agree with it', function (): void {
    $panel = deployPanelFor("drivers:\n  cognition: psychsim\n  mode: external\n  memory: agentos\n  experience: cbrkit\n");

    $needed = collect($panel['services'])->where('needed', true)->pluck('service')->values()->all();

    expect($needed)->toBe(['psychsim', 'cbrkit', 'agentos'])
        ->and($panel['up'])->toContain('up -d psychsim cbrkit agentos')
        ->and($panel['down'])->toContain('stop fatima');
});
