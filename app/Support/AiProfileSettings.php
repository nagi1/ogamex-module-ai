<?php

namespace Modules\AI\Support;

use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;

final class AiProfileSettings
{
    public const BUILDING_WEIGHTS = 'building_weights';

    public static function buildingWeight(AiProfile $profile, FirstBuildingTarget $target): int
    {
        return (int) ($profile->settings[self::BUILDING_WEIGHTS][$target->name] ?? 0);
    }
}
