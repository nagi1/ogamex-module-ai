<?php

namespace Modules\AI\Support;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The module's single deployment configuration, read from one YAML file.
 *
 * Deployment wiring — drivers, sidecar endpoints, worker sizing, provider lanes and the
 * structured policy (ladders, pricing, triggers, retention) — lives in `ai-settings.yaml`.
 * Scalar runtime guardrails move to the host's settings table in a later slice, so they are
 * deliberately absent from this schema.
 *
 * This class is the schema: the defaults array is the shape a value must match, and a value
 * whose type differs from its default — including the YAML `on`/`off`/`yes`/`no` boolean trap —
 * is rejected with the key. A missing file, or a missing key, resolves to the default, so a
 * fresh deployment needs no file at all.
 */
final class AiSettings
{
    /**
     * The full deployment schema and its defaults. The defaults mirror the values the module's
     * `config/*.php` files shipped before this file existed, so a missing file behaves exactly
     * like today.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'drivers' => [
            // Affect and social cognition share one selection because the two contracts must
            // share a single integrated character state.
            'cognition' => 'fatima',
            'mode' => 'hybrid',
            'memory' => 'agentos',
            'experience' => 'cbrkit',
        ],
        'circuit' => [
            'failures' => 3,
            'cooldown_seconds' => 60,
        ],
        'payload' => [
            'maximum_response_bytes' => 262144,
        ],
        'fatima' => [
            'base_url' => 'http://host.docker.internal:8092',
            'connect_timeout_seconds' => 2,
            'timeout_seconds' => 5,
            'lock_seconds' => 10,
            'scenario' => 'OgameCognition',
            'scenario_path' => null,
            'instance' => 1,
            'counterparty' => 'Other',
            'exchange' => 'CooperativeMove',
            'intensity_ceiling' => 1.0,
            'rapport_scale' => 10.0,
        ],
        'cbrkit' => [
            'base_url' => 'http://host.docker.internal:8091',
            'connect_timeout_seconds' => 2,
            'timeout_seconds' => 5,
            'maximum_cases' => 200,
        ],
        'agentos' => [
            'base_url' => 'http://host.docker.internal:8093',
            'connect_timeout_seconds' => 2,
            'timeout_seconds' => 5,
            'maximum_memories' => 50,
        ],
        'psychsim' => [
            'base_url' => 'http://host.docker.internal:8094',
            'connect_timeout_seconds' => 2,
            'timeout_seconds' => 5,
        ],
        'language' => [
            'provider' => 'deepseek',
            'model' => 'deepseek-flash',
            'universe_scope' => 'default',
        ],
        'campaign' => [
            'provider' => 'deepseek',
            'model' => 'deepseek-v4-pro',
        ],
        'routing' => [
            'enabled' => false,
            'windows' => [
                'deepseek_peak' => [
                    'days' => [1, 2, 3, 4, 5],
                    'periods' => [['01:00', '04:00'], ['06:00', '10:00']],
                ],
            ],
            'vendors' => [
                'deepseek' => ['window' => 'deepseek_peak'],
                'openai' => [],
            ],
            'ladders' => [
                'conversation_reply' => [
                    ['provider' => 'deepseek', 'model' => 'deepseek-flash'],
                    ['provider' => 'openai', 'model' => 'gpt-5.6-luna'],
                ],
                'campaign_consultation' => [
                    ['provider' => 'deepseek', 'model' => 'deepseek-v4-pro'],
                ],
                'conformance' => [
                    ['provider' => 'deepseek', 'model' => 'deepseek-flash'],
                ],
            ],
        ],
        'pricing' => [
            'currency' => 'usd',
            'peak_multiplier' => 2.0,
            'rates' => [
                'deepseek.deepseek-flash' => ['input' => 0.15, 'cached_input' => 0.003, 'output' => 0.60],
                'deepseek.deepseek-v4-pro' => ['input' => 0.66, 'cached_input' => 0.022, 'output' => 1.98],
                'openai.gpt-5.6-luna' => ['input' => 0.20, 'cached_input' => 0.02, 'output' => 1.20],
            ],
        ],
        'horizon' => [
            'enabled' => true,
            'processes' => [
                'supervisor-ai' => 1,
                'supervisor-ai-language' => 1,
            ],
            'waits' => [
                'ai' => 120,
                'ai-language' => 180,
            ],
        ],
        'population' => [
            // The one population knob that changes schedule registration (boot-time), so it
            // stays deployment rather than moving to the host settings table with the caps.
            'session_interval_seconds' => 0,
        ],
    ];

    /**
     * String keys that may only take one of these values. Every other string key accepts any
     * string; these are the module's own enums.
     *
     * @var array<string, list<string>>
     */
    private const ENUMS = [
        'drivers.cognition' => ['native', 'fatima', 'psychsim'],
        'drivers.mode' => ['native', 'external', 'hybrid'],
        'drivers.memory' => ['native', 'agentos'],
        'drivers.experience' => ['native', 'cbrkit'],
    ];

    /**
     * The one-release back-compat shim: while a config file still reads its scattered `AI_*`
     * variable, that variable wins over the file. Flat dot-key => environment variable.
     *
     * @var array<string, string>
     */
    private const ENV_OVERRIDES = [
        'drivers.cognition' => 'AI_COGNITION_DRIVER',
        'drivers.mode' => 'AI_COGNITION_MODE',
        'drivers.memory' => 'AI_MEMORY_DRIVER',
        'drivers.experience' => 'AI_EXPERIENCE_DRIVER',
        'circuit.failures' => 'AI_COGNITION_CIRCUIT_FAILURES',
        'circuit.cooldown_seconds' => 'AI_COGNITION_CIRCUIT_COOLDOWN_SECONDS',
        'payload.maximum_response_bytes' => 'AI_COGNITION_MAXIMUM_RESPONSE_BYTES',
        'fatima.base_url' => 'AI_COGNITION_FATIMA_URL',
        'fatima.connect_timeout_seconds' => 'AI_COGNITION_FATIMA_CONNECT_TIMEOUT_SECONDS',
        'fatima.timeout_seconds' => 'AI_COGNITION_FATIMA_TIMEOUT_SECONDS',
        'fatima.lock_seconds' => 'AI_COGNITION_FATIMA_LOCK_SECONDS',
        'fatima.scenario' => 'AI_COGNITION_FATIMA_SCENARIO',
        'fatima.scenario_path' => 'AI_COGNITION_FATIMA_SCENARIO_PATH',
        'fatima.instance' => 'AI_COGNITION_FATIMA_INSTANCE',
        'fatima.counterparty' => 'AI_COGNITION_FATIMA_COUNTERPARTY',
        'fatima.exchange' => 'AI_COGNITION_FATIMA_EXCHANGE',
        'fatima.intensity_ceiling' => 'AI_COGNITION_FATIMA_INTENSITY_CEILING',
        'fatima.rapport_scale' => 'AI_COGNITION_FATIMA_RAPPORT_SCALE',
        'cbrkit.base_url' => 'AI_EXPERIENCE_CBRKIT_URL',
        'cbrkit.connect_timeout_seconds' => 'AI_EXPERIENCE_CBRKIT_CONNECT_TIMEOUT_SECONDS',
        'cbrkit.timeout_seconds' => 'AI_EXPERIENCE_CBRKIT_TIMEOUT_SECONDS',
        'cbrkit.maximum_cases' => 'AI_EXPERIENCE_CBRKIT_MAXIMUM_CASES',
        'agentos.base_url' => 'AI_MEMORY_AGENTOS_URL',
        'agentos.connect_timeout_seconds' => 'AI_MEMORY_AGENTOS_CONNECT_TIMEOUT_SECONDS',
        'agentos.timeout_seconds' => 'AI_MEMORY_AGENTOS_TIMEOUT_SECONDS',
        'agentos.maximum_memories' => 'AI_MEMORY_AGENTOS_MAXIMUM_MEMORIES',
        'psychsim.base_url' => 'AI_COGNITION_PSYCHSIM_URL',
        'psychsim.connect_timeout_seconds' => 'AI_COGNITION_PSYCHSIM_CONNECT_TIMEOUT_SECONDS',
        'psychsim.timeout_seconds' => 'AI_COGNITION_PSYCHSIM_TIMEOUT_SECONDS',
        'language.provider' => 'AI_LANGUAGE_PROVIDER',
        'language.model' => 'AI_LANGUAGE_MODEL',
        'language.universe_scope' => 'AI_LANGUAGE_UNIVERSE_SCOPE',
        'campaign.provider' => 'AI_CAMPAIGN_CONSULTATION_PROVIDER',
        'campaign.model' => 'AI_CAMPAIGN_CONSULTATION_MODEL',
        'routing.enabled' => 'AI_ROUTING_ENABLED',
        'population.session_interval_seconds' => 'AI_POPULATION_SESSION_INTERVAL_SECONDS',
        'horizon.enabled' => 'AI_HORIZON_ENABLED',
    ];

    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * Resolve the deployment settings from the file at the given path, the `AI_SETTINGS_FILE`
     * variable, or the module default — in that order — and apply the one-release env shim.
     */
    public static function resolve(?string $filePath = null): self
    {
        $path = $filePath ?? env('AI_SETTINGS_FILE') ?: base_path('Modules/AI/ai-settings.yaml');

        $values = self::DEFAULTS;

        if (is_file($path)) {
            try {
                $parsed = Yaml::parseFile($path);
            } catch (ParseException $exception) {
                throw new RuntimeException(
                    'ai-settings: '.$exception->getMessage(),
                    0,
                    $exception,
                );
            }

            if (!is_array($parsed)) {
                throw new RuntimeException('ai-settings: the file must contain a YAML mapping.');
            }

            self::assertMatches($parsed, self::DEFAULTS);
            $values = array_replace_recursive($values, $parsed);
        }

        $values = self::applyEnvOverrides($values);

        return new self($values);
    }

    /**
     * A value from the deployment settings, by dot notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cursor = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * The whole resolved deployment tree, for the settings screen's read-only rendering.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Walk the file's values against the defaults: an unknown key is refused, a scalar whose
     * type differs from its default is refused (this is what catches an unquoted `off`), and an
     * enum key outside its allow-list is refused.
     *
     * @param array<string, mixed> $provided
     * @param array<string, mixed> $defaults
     */
    private static function assertMatches(array $provided, array $defaults, string $prefix = ''): void
    {
        foreach ($provided as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (!array_key_exists($key, $defaults)) {
                throw new RuntimeException(sprintf('ai-settings: unknown key "%s".', $path));
            }

            $default = $defaults[$key];

            if (is_array($default)) {
                if (!is_array($value)) {
                    throw new RuntimeException(sprintf(
                        'ai-settings: "%s" expected a mapping, got %s.',
                        $path,
                        gettype($value),
                    ));
                }

                self::assertMatches($value, $default, $path);

                continue;
            }

            if ($default === null) {
                continue;
            }

            if (gettype($value) !== gettype($default)) {
                throw new RuntimeException(sprintf(
                    'ai-settings: "%s" expected %s, got %s.',
                    $path,
                    gettype($default),
                    gettype($value),
                ));
            }

            if (isset(self::ENUMS[$path]) && !in_array($value, self::ENUMS[$path], true)) {
                throw new RuntimeException(sprintf(
                    'ai-settings: "%s" must be one of %s.',
                    $path,
                    implode(' | ', self::ENUMS[$path]),
                ));
            }
        }
    }

    /**
     * The one-release shim: an explicitly set `AI_*` variable wins over the file, cast to the
     * type its default declares. This keeps an existing deployment's scattered variables working
     * until the config files become readers of this object and the shim is removed.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function applyEnvOverrides(array $values): array
    {
        foreach (self::ENV_OVERRIDES as $key => $variable) {
            $raw = env($variable);

            if ($raw === null || (is_string($raw) && $raw === '')) {
                continue;
            }

            $default = self::DEFAULTS;
            foreach (explode('.', $key) as $segment) {
                $default = $default[$segment] ?? null;
            }

            $values = self::setByDot($values, $key, self::cast($default, $raw));
        }

        return $values;
    }

    private static function cast(mixed $default, string $value): mixed
    {
        return match (gettype($default)) {
            'integer' => (int) $value,
            'double' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    private static function setByDot(array $array, string $key, mixed $value): array
    {
        $segments = explode('.', $key);
        $cursor = &$array;

        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor = $value;

        return $array;
    }
}
