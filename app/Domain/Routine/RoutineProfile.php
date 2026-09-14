<?php

namespace Modules\AI\Domain\Routine;

use DateTimeZone;
use Exception;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiProfileSettings;

readonly class RoutineProfile
{
    private const DEFAULT_SESSION_MINUTES = 12;

    private const MINIMUM_MINUTES = 1;

    public function __construct(
        public string $timezone,
        public int $sessionMinutes,
        public int $sessionsPerDay,
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
            'sessionsPerDay' => self::sessionsPerDay($profile->archetype),
        ]);
    }

    /**
     * How often each kind of player looks at the account in a day.
     *
     * This is archetype taste, not a game rule: a casual player opens the game a
     * couple of times around work, and a fleeter lives in it. The figure is the
     * target the waits are drawn around, not the visits a month actually
     * contains: the longest waits are spent asleep, so a persona lands somewhat
     * above its target, and the targets are set from that measurement.
     */
    private static function sessionsPerDay(AiArchetype $archetype): int
    {
        return match ($archetype) {
            AiArchetype::Casual => 2,
            AiArchetype::Trader => 4,
            AiArchetype::Turtle => 5,
            AiArchetype::Miner => 5,
            AiArchetype::Fleeter => 11,
        };
    }
}
