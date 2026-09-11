<?php

namespace Modules\AI\Support;

use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;

final class AiProfileSettings
{
    public const BUILDING_WEIGHTS = 'building_weights';

    public const TIMEZONE = 'timezone';

    public const SESSION_MINUTES = 'session_minutes';

    public const SESSION_GAP_MINUTES = 'session_gap_minutes';

    public const RECOVERY_FACTOR = 'recovery_factor';

    public const DEFAULT_TIMEZONE = 'UTC';

    public static function buildingWeight(AiProfile $profile, FirstBuildingTarget $target): int
    {
        return (int) ($profile->settings[self::BUILDING_WEIGHTS][$target->name] ?? 0);
    }
}
