<?php

namespace Modules\AI\Support;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * What a Collector's crawler wing adds to base production, and what it draws from the grid.
 *
 * The two are reported apart on purpose: the energy multiplier is doubled by the efficiency
 * setting, and a caller that let it scale the bonus would silently overstate production.
 */
final class CollectorCrawlerBonusCalculator
{
    private const BEHAVIOR_FILE = '/resources/behavior/collector.yaml';

    /** @var array<string, mixed>|null */
    private ?array $figures = null;

    /**
     * Share of base production the given crawler wing adds, in percent, capped at the wing's ceiling.
     *
     * @param int $crawlerCount number of crawlers the account owns
     * @param int $efficiencyPercent crawler efficiency setting (100 or 150)
     */
    public function productionBonusPercent(int $crawlerCount, int $efficiencyPercent): float
    {
        if ($crawlerCount <= 0) {
            return 0.0;
        }

        return min(
            $crawlerCount * $this->perCrawlerBonusPercent($efficiencyPercent),
            (float) $this->crawlerFigures()['max_bonus_percent'],
        );
    }

    /**
     * Multiplier the crawler wing applies to its energy consumption at the given efficiency.
     */
    public function energyMultiplier(int $efficiencyPercent): float
    {
        return (float) $this->efficiencyFigures($efficiencyPercent)['energy_multiplier'];
    }

    private function perCrawlerBonusPercent(int $efficiencyPercent): float
    {
        $crawler = $this->crawlerFigures();

        return (float) $crawler['base_bonus_percent']
            * (float) $crawler['collector_class_multiplier']
            * (float) $this->efficiencyFigures($efficiencyPercent)['production_multiplier'];
    }

    /**
     * @return array<string, mixed>
     */
    private function crawlerFigures(): array
    {
        return $this->loadFigures()['crawler'];
    }

    /**
     * @return array<string, mixed>
     */
    private function efficiencyFigures(int $efficiencyPercent): array
    {
        $efficiencies = $this->loadFigures()['efficiency'];

        if (isset($efficiencies[$efficiencyPercent])) {
            return $efficiencies[$efficiencyPercent];
        }

        throw new InvalidArgumentException(sprintf(
            'Crawler efficiency %d%% is not documented in %s.',
            $efficiencyPercent,
            self::BEHAVIOR_FILE,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFigures(): array
    {
        return $this->figures ??= Yaml::parseFile(dirname(__DIR__, 2) . self::BEHAVIOR_FILE);
    }
}
