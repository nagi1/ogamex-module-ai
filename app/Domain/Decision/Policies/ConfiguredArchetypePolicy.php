<?php

namespace Modules\AI\Domain\Decision\Policies;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * A legal intent set plus the taste among it, read per archetype from one data file.
 *
 * The weights live in `resources/behavior/archetype-preferences.yaml` only: an archetype states
 * how readily its account takes each errand, so one tuning pass moves the whole dimension there
 * and no policy class restates a number (PERS-008).
 */
abstract class ConfiguredArchetypePolicy implements ArchetypePolicy
{
    /** Module root relative, so the taste of every archetype is read by name. */
    private const PREFERENCES_FILE = '/resources/behavior/archetype-preferences.yaml';

    /** @var array<int, float> action value => weight */
    protected array $preferences = [];

    /** @var array<int, bool> action value => legality, when a policy marks an intent illegal */
    protected array $allowed = [];

    public function __construct()
    {
        $this->preferences = self::table()[strtolower($this->archetype()->name)] ?? [];
    }

    public function allows(AiCandidateActionType $action): bool
    {
        return $this->allowed[$action->value] ?? true;
    }

    public function preference(AiCandidateActionType $action): float
    {
        return $this->preferences[$action->value] ?? 0.0;
    }

    /**
     * The file's sections keyed by archetype name, each action name resolved to its enum value:
     * a typo in the file is an error rather than a taste that quietly applies to nobody.
     *
     * @return array<string, array<int, float>>
     */
    private static function table(): array
    {
        static $table = null;

        if ($table !== null) {
            return $table;
        }

        $path = dirname(__DIR__, 4) . self::PREFERENCES_FILE;
        $parsed = is_file($path) ? Yaml::parseFile($path) : null;
        $table = [];

        foreach (is_array($parsed) ? $parsed : [] as $archetype => $weights) {
            $row = [];

            foreach (is_array($weights) ? $weights : [] as $action => $weight) {
                $row[self::intent((string) $action)->value] = (float) $weight;
            }

            $table[strtolower((string) $archetype)] = $row;
        }

        return $table;
    }

    private static function intent(string $name): AiCandidateActionType
    {
        // Keys are written snake_case (`fleet_save`), enum cases are not (`FleetSave`).
        $normalised = strtolower(str_replace('_', '', $name));

        foreach (AiCandidateActionType::cases() as $case) {
            if (strtolower($case->name) === $normalised) {
                return $case;
            }
        }

        throw app()->makeWith(RuntimeException::class, [
            'message' => 'archetype-preferences: "' . $name . '" is not an intent (' . basename(self::PREFERENCES_FILE) . ').',
        ]);
    }

    abstract public function archetype(): AiArchetype;
}
