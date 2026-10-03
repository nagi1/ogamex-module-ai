<?php

namespace Modules\AI\Domain\Expedition;

use Symfony\Component\Yaml\Yaml;

final class DebrisFieldRatio
{
    private const BEHAVIOR_FILE = '/resources/behavior/expedition.yaml';

    public function share(bool $discoverer): float
    {
        $shares = Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE)['debris_field_share'];

        return (float) ($discoverer ? $shares['discoverer'] : $shares['default']);
    }

    public function debris(float $expeditionResourceValue, bool $discoverer): float
    {
        return $expeditionResourceValue * $this->share($discoverer);
    }
}
