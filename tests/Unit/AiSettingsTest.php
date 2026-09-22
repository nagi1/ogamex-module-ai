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

it('rejects a value whose parsed type differs from the default', function () {
    $path = writeAiSettingsFile("circuit:\n  failures: \"3\"\n");

    try {
        expect(fn () => AiSettings::resolve($path))
            ->toThrow(RuntimeException::class, 'circuit.failures');
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

/**
 * @param callable(): void $body
 */
function withAiEnv(string $variable, string $value, callable $body): void
{
    $original = $_ENV[$variable] ?? null;
    $_ENV[$variable] = $value;

    try {
        $body();
    } finally {
        if ($original === null) {
            unset($_ENV[$variable]);
        } else {
            $_ENV[$variable] = $original;
        }
    }
}

it('casts an integer env override to int', function () {
    withAiEnv('AI_COGNITION_CIRCUIT_FAILURES', '7', function () {
        expect(AiSettings::resolve('/nonexistent/ai-settings.yaml')->get('circuit.failures'))->toBe(7);
    });
});

it('casts a float env override to float', function () {
    withAiEnv('AI_COGNITION_FATIMA_INTENSITY_CEILING', '0.75', function () {
        expect(AiSettings::resolve('/nonexistent/ai-settings.yaml')->get('fatima.intensity_ceiling'))->toBe(0.75);
    });
});

it('casts a boolean env override to bool', function () {
    withAiEnv('AI_HORIZON_ENABLED', 'false', function () {
        expect(AiSettings::resolve('/nonexistent/ai-settings.yaml')->get('horizon.enabled'))->toBeFalse();
    });
});

it('refuses a file that is not a mapping', function () {
    $path = writeAiSettingsFile('just a string');

    try {
        expect(fn () => AiSettings::resolve($path))->toThrow(RuntimeException::class, 'mapping');
    } finally {
        unlink($path);
    }
});

it('refuses a scalar where a mapping is expected', function () {
    $path = writeAiSettingsFile("drivers: fatima\n");

    try {
        expect(fn () => AiSettings::resolve($path))->toThrow(RuntimeException::class, 'expected a mapping');
    } finally {
        unlink($path);
    }
});

it('returns the default for a missing key', function () {
    $settings = AiSettings::resolve('/nonexistent/ai-settings.yaml');

    expect($settings->get('nonexistent.key', 'fallback'))->toBe('fallback')
        ->and($settings->get('drivers.nope'))->toBeNull();
});

it('returns the default when a key path passes through a scalar', function () {
    $settings = AiSettings::resolve('/nonexistent/ai-settings.yaml');

    expect($settings->get('drivers.cognition.extra', 'fallback'))->toBe('fallback');
});

it('ignores an empty env override', function () {
    withAiEnv('AI_COGNITION_DRIVER', '', function () {
        expect(AiSettings::resolve('/nonexistent/ai-settings.yaml')->get('drivers.cognition'))->toBe('fatima');
    });
});

it('honours the AI_SETTINGS_FILE variable', function () {
    $path = writeAiSettingsFile("drivers:\n  memory: native\n");

    withAiEnv('AI_SETTINGS_FILE', $path, function () use ($path) {
        try {
            expect(AiSettings::resolve()->get('drivers.memory'))->toBe('native');
        } finally {
            unlink($path);
        }
    });
});

it('accepts an explicit null for a nullable key', function () {
    $path = writeAiSettingsFile("fatima:\n  scenario_path: null\n");

    try {
        expect(AiSettings::resolve($path)->get('fatima.scenario_path'))->toBeNull();
    } finally {
        unlink($path);
    }
});
