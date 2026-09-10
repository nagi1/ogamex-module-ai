<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiProfileSettings;

class SeededBuildingScoringPolicy implements BuildingScoringPolicy
{
    private const BASE_SCORE = 100;

    private const VARIATION_RANGE = 10;

    public function score(AiProfile $profile, FirstBuildingTarget $target): int
    {
        $seed = $profile->random_seed . ':' . $target->value;
        $variation = crc32($seed) % self::VARIATION_RANGE;

        return self::BASE_SCORE + AiProfileSettings::buildingWeight($profile, $target) + $variation;
    }
}
