<?php

namespace Modules\AI\Domain\Routine;

use DateTimeZone;
use Exception;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiProfileSettings;

readonly class RoutineProfile
{
    private const DEFAULT_SESSION_MINUTES = 12;

    private const DEFAULT_SESSION_GAP_MINUTES = 45;

    private const MINIMUM_MINUTES = 1;

    public function __construct(
        public string $timezone,
        public int $sessionMinutes,
        public int $sessionGapMinutes,
    ) {
    }

    public static function fromAiProfile(AiProfile $profile): self
    {
        $settings = $profile->settings ?? [];
        $timezone = (string) ($settings[AiProfileSettings::TIMEZONE] ?? AiProfileSettings::DEFAULT_TIMEZONE);

        try {
            app()->makeWith(DateTimeZone::class, ['timezone' => $timezone]);
        } catch (Exception) {
            $timezone = AiProfileSettings::DEFAULT_TIMEZONE;
        }

        return app()->makeWith(self::class, [
            'timezone' => $timezone,
            'sessionMinutes' => max(self::MINIMUM_MINUTES, (int) ($settings[AiProfileSettings::SESSION_MINUTES] ?? self::DEFAULT_SESSION_MINUTES)),
            'sessionGapMinutes' => max(self::MINIMUM_MINUTES, (int) ($settings[AiProfileSettings::SESSION_GAP_MINUTES] ?? self::DEFAULT_SESSION_GAP_MINUTES)),
        ]);
    }
}
