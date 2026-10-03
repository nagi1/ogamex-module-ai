<?php

namespace Modules\AI\Domain\Market;

use Symfony\Component\Yaml\Yaml;

/**
 * A marketplace search offer is actionable only inside the documented band around the current ratio
 * (metal:crystal:deuterium, 2.5:1.5:1 as standard); every leg must be inside it.
 */
final class MarketRatioBand
{
    private const BEHAVIOR_FILE = '/resources/behavior/marketplace-ratio-documented.yaml';

    /**
     * @param array{metal: float, crystal: float, deuterium: float} $offerRatio
     * @param array{metal: float, crystal: float, deuterium: float}|null $currentRatio the server's current ratio; standard when null
     */
    public function accepts(array $offerRatio, ?array $currentRatio = null): bool
    {
        $policy = Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE);
        $current = $currentRatio ?? $policy['standard_ratio'];
        $band = (float) $policy['offer_band_percent'] / 100;

        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $reference = (float) $current[$resource];
            if ($reference <= 0.0) {
                return false;
            }
            if (abs((float) $offerRatio[$resource] - $reference) / $reference > $band) {
                return false;
            }
        }

        return true;
    }
}
