<?php

namespace Modules\AI\Domain\Defense;

/**
 * The anti-ballistic missile policy: what a Missile Silo stores, what one missile
 * costs, and the stats a defensive decision budgets against.
 *
 * Every number lives in resources/behavior/def-ipm.yaml, so the defensive budget
 * is retuned by a modder editing that file rather than by editing PHP.
 */
final class AntiBallisticMissile
{
    private const POLICY_FILE = '/resources/behavior/def-ipm.yaml';
    private const ENTRY_SEPARATOR = ':';
    private const COMMENT_PREFIX = '#';

    private const KEY_ABM_PER_SILO_LEVEL = 'abm_per_missile_silo_level';
    private const KEY_IPM_PER_SILO_LEVEL = 'ipm_per_missile_silo_level';
    private const KEY_IPM_TO_ABM_CAPACITY_RATIO = 'ipm_to_abm_capacity_ratio';
    private const KEY_COST_METAL = 'abm_cost_metal';
    private const KEY_COST_CRYSTAL = 'abm_cost_crystal';
    private const KEY_COST_DEUTERIUM = 'abm_cost_deuterium';
    private const KEY_INTEGRITY = 'abm_integrity';
    private const KEY_SHIELD = 'abm_shield';
    private const KEY_WEAPON = 'abm_weapon';
    private const KEY_PREREQUISITE_MISSILE_SILO_LEVEL = 'abm_prerequisite_missile_silo_level';

    /** @param array<string, int|string> $entries */
    private function __construct(private readonly array $entries)
    {
    }

    public static function policy(): self
    {
        $path = dirname(__DIR__, 3) . self::POLICY_FILE;

        return new self(self::parse((string) file_get_contents($path)));
    }

    /**
     * A Missile Silo level stores ten anti-ballistic missiles, so the silo level
     * is the cap on how many an account may keep at once.
     */
    public function abmCapacity(int $missileSiloLevel): int
    {
        return $this->entry(self::KEY_ABM_PER_SILO_LEVEL) * $missileSiloLevel;
    }

    public function ipmCapacity(int $missileSiloLevel): int
    {
        return $this->entry(self::KEY_IPM_PER_SILO_LEVEL) * $missileSiloLevel;
    }

    /** Interplanetary missiles storable per anti-ballistic missile storable, as stated. */
    public function ipmToAbmCapacityRatio(): string
    {
        return $this->text(self::KEY_IPM_TO_ABM_CAPACITY_RATIO);
    }

    /** @return array{metal: int, crystal: int, deuterium: int} */
    public function cost(): array
    {
        return [
            'metal' => $this->entry(self::KEY_COST_METAL),
            'crystal' => $this->entry(self::KEY_COST_CRYSTAL),
            'deuterium' => $this->entry(self::KEY_COST_DEUTERIUM),
        ];
    }

    public function prerequisiteMissileSiloLevel(): int
    {
        return $this->entry(self::KEY_PREREQUISITE_MISSILE_SILO_LEVEL);
    }

    public function integrity(): int
    {
        return $this->entry(self::KEY_INTEGRITY);
    }

    public function shield(): int
    {
        return $this->entry(self::KEY_SHIELD);
    }

    public function weapon(): int
    {
        return $this->entry(self::KEY_WEAPON);
    }

    private function entry(string $key): int
    {
        return (int) $this->entries[$key];
    }

    private function text(string $key): string
    {
        return (string) $this->entries[$key];
    }

    /**
     * The policy file is flat `key: value` YAML so a modder can read and edit it,
     * and the module parses it without pulling in a YAML parser.
     *
     * @return array<string, int|string>
     */
    private static function parse(string $contents): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, self::COMMENT_PREFIX)) {
                continue;
            }

            if (! str_contains($line, self::ENTRY_SEPARATOR)) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(self::ENTRY_SEPARATOR, $line, 2));

            $entries[$key] = is_numeric($value) ? (int) $value : $value;
        }

        return $entries;
    }
}
