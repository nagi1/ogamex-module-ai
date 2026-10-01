<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Decision;

/**
 * Cargo lift for one raid on one target, split into the waves the attack limit allows.
 *
 * A planet or moon can only be attacked six times within 24 hours on a base-speed server, so the
 * cap belongs to the number of waves and not to the ships inside them: the plan can never hand
 * back a seventh wave for a target that was already raided in the same window.
 */
final readonly class RaidWavePlan
{
    /** One cargo unit is loaded for every 50k resources the target holds. */
    public const int RESOURCES_PER_CARGO_UNIT = 50_000;

    /** Halving a wave never drops below a single cargo unit. */
    public const int MINIMUM_WAVE_SIZE = 1;

    /**
     * @param list<int> $waves
     */
    private function __construct(private array $waves)
    {
    }

    public static function forTarget(int $resources): self
    {
        $waves = [];
        $size = self::firstWaveSize($resources);

        for ($wave = 0; $wave < RaidPlanner::BASHING_LIMIT; $wave++) {
            $waves[] = $size;
            $size = self::halfOf($size);
        }

        return new self($waves);
    }

    /**
     * @return list<int>
     */
    public function waves(): array
    {
        return $this->waves;
    }

    public function count(): int
    {
        return count($this->waves);
    }

    public function firstWave(): int
    {
        return $this->waves[0];
    }

    /**
     * The floor of one unit is stated for the halving of later waves only, so an empty target is
     * honestly reported as needing no cargo units up front instead of a wave the resources do not
     * cover.
     */
    private static function firstWaveSize(int $resources): int
    {
        return (int) ceil($resources / self::RESOURCES_PER_CARGO_UNIT);
    }

    private static function halfOf(int $size): int
    {
        return max(self::MINIMUM_WAVE_SIZE, (int) ceil($size / 2));
    }
}
