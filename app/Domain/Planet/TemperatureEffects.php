<?php

namespace Modules\AI\Domain\Planet;

use Symfony\Component\Yaml\Yaml;

/**
 * The temperature policy in resources/behavior/temperature.yaml as arithmetic: energy per Solar
 * Satellite on a slot, and which of two planets suits satellites (hotter) or deuterium (colder).
 */
final class TemperatureEffects
{
    private const BEHAVIOR_FILE = '/resources/behavior/temperature.yaml';

    public function satelliteEnergy(int $maxTemperature): int
    {
        $policy = Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE)['solar_satellite'];
        $energy = (int) floor(($maxTemperature + (int) $policy['energy_offset']) / (int) $policy['degrees_per_energy']);

        return max(0, min((int) $policy['max_energy'], $energy));
    }

    /**
     * @param array<int, int> $temperaturesByPlanetId max temperature per planet id
     */
    public function hottestPlanetId(array $temperaturesByPlanetId): ?int
    {
        if ($temperaturesByPlanetId === []) {
            return null;
        }
        arsort($temperaturesByPlanetId);

        return array_key_first($temperaturesByPlanetId);
    }

    /**
     * @param array<int, int> $temperaturesByPlanetId max temperature per planet id
     */
    public function coldestPlanetId(array $temperaturesByPlanetId): ?int
    {
        if ($temperaturesByPlanetId === []) {
            return null;
        }
        asort($temperaturesByPlanetId);

        return array_key_first($temperaturesByPlanetId);
    }
}
