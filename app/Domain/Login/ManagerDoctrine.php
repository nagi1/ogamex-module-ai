<?php

namespace Modules\AI\Domain\Login;

use Modules\AI\Enums\AiArchetype;
use Symfony\Component\Yaml\Yaml;

/**
 * The per-archetype numbers the login managers run on (`resources/doctrine/managers.yaml`). A missing
 * archetype or key falls back to the file's `default` section, so a new archetype plays like an average one.
 */
class ManagerDoctrine
{
    private const FILE = '/resources/doctrine/managers.yaml';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $table = null;

    public function int(AiArchetype $archetype, string $key): int
    {
        return (int) $this->value($archetype, $key, 0);
    }

    public function bool(AiArchetype $archetype, string $key): bool
    {
        return (bool) $this->value($archetype, $key, false);
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
