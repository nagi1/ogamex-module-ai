<?php

use Modules\AI\Support\AiSettings;

function writeAiSettingsFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'ai-settings');
    file_put_contents($path, $contents);

    return $path;
}

it('resolves defaults when no file exists', function () {
    $settings = AiSettings::resolve('/nonexistent/ai-settings.yaml');

    expect($settings->get('drivers.cognition'))->toBe('fatima')
        ->and($settings->get('drivers.mode'))->toBe('hybrid')
        ->and($settings->get('language.provider'))->toBe('deepseek')
        ->and($settings->get('pricing.peak_multiplier'))->toBe(2.0);
});

it('reads a valid file and keeps defaults for unspecified keys', function () {
    $path = writeAiSettingsFile(<<<YAML
pricing:
  peak_multiplier: 3.5
  currency: eur
YAML);

    try {
        $settings = AiSettings::resolve($path);

        expect($settings->get('pricing.peak_multiplier'))->toBe(3.5)
            ->and($settings->get('pricing.currency'))->toBe('eur')
            ->and($settings->get('pricing.rates')['deepseek.deepseek-flash']['input'])->toBe(0.15)
            ->and($settings->get('drivers.memory'))->toBe('agentos');
    } finally {
        unlink($path);
    }
});

it('fails loudly on a syntax error with the line', function () {
    $path = writeAiSettingsFile("drivers:\n  cognition: [unclosed\n");

    try {
        expect(fn () => AiSettings::resolve($path))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
});

it('rejects an unquoted off parsed as a boolean, naming the key', function () {
    $path = writeAiSettingsFile("drivers:\n  mode: off\n");

    try {
        expect(fn () => AiSettings::resolve($path))
            ->toThrow(RuntimeException::class, 'drivers.mode');
    } finally {
        unlink($path);
    }
});

it('rejects an enum value outside the allow-list', function () {
    $path = writeAiSettingsFile("drivers:\n  cognition: bogus\n");

    try {
        expect(fn () => AiSettings::resolve($path))
            ->toThrow(RuntimeException::class, 'drivers.cognition');
    } finally {
        unlink($path);
    }
});

it('rejects an unknown key', function () {
    $path = writeAiSettingsFile("drivers:\n  nope: 1\n");

    try {
        expect(fn () => AiSettings::resolve($path))
            ->toThrow(RuntimeException::class, 'nope');
    } finally {
        unlink($path);
    }
});

it('lets an explicitly set AI_* variable win for one release', function () {
    $original = $_ENV['AI_COGNITION_DRIVER'] ?? null;
    $_ENV['AI_COGNITION_DRIVER'] = 'psychsim';

    try {
        $settings = AiSettings::resolve('/nonexistent/ai-settings.yaml');

        expect($settings->get('drivers.cognition'))->toBe('psychsim');
    } finally {
        if ($original === null) {
            unset($_ENV['AI_COGNITION_DRIVER']);
        } else {
            $_ENV['AI_COGNITION_DRIVER'] = $original;
        }
    }
});
