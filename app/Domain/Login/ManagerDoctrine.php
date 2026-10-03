<?php

namespace Modules\AI\Domain\Login;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\GamePhase;
use Symfony\Component\Yaml\Yaml;

/**
 * The per-archetype numbers the login managers run on (`resources/doctrine/managers.yaml`). A missing
 * archetype or key falls back to the file's `default` section, so a new archetype plays like an average one.
 *
 * A phase may scale a manager's numbers (`phases: <phase>: <key>`): the archetype's own number stays the
 * base and the phase's value multiplies it. A phase or key the file does not name multiplies by one, so a
 * missing phase changes nothing (Gate 1).
 */
class ManagerDoctrine
{
    private const FILE = '/resources/doctrine/managers.yaml';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $table = null;

    public function int(AiArchetype $archetype, string $key, ?GamePhase $phase = null): int
    {
        return (int) round($this->value($archetype, $key, 0) * $this->scale($archetype, $phase, $key));
    }

    public function bool(AiArchetype $archetype, string $key): bool
    {
        return (bool) $this->value($archetype, $key, false);
    }

    /** The multiplier this phase gives the key, from the archetype's own phase block or the default one. */
    private function scale(AiArchetype $archetype, ?GamePhase $phase, string $key): float
    {
        if ($phase === null) {
            return 1.0;
        }

        $table = self::table();

        foreach ([$table[strtolower($archetype->name)] ?? [], $table['default'] ?? []] as $row) {
            $phases = $row['phases'] ?? null;
            $phaseRow = is_array($phases) ? ($phases[$phase->value] ?? null) : null;
            $value = is_array($phaseRow) ? ($phaseRow[$key] ?? null) : null;

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return 1.0;
    }

    private function value(AiArchetype $archetype, string $key, mixed $fallback): mixed
    {
        $table = self::table();
        $row = $table[strtolower($archetype->name)] ?? [];

        return $row[$key] ?? $table['default'][$key] ?? $fallback;
    }

    /** @return array<string, array<string, mixed>> */
    private static function table(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }

        $path = dirname(__DIR__, 3) . self::FILE;
        $parsed = is_file($path) ? Yaml::parseFile($path) : [];

        return self::$table = is_array($parsed) ? $parsed : [];
    }
}
