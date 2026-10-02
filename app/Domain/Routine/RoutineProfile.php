<?php

namespace Modules\AI\Domain\Routine;

use DateTimeZone;
use Exception;
use Modules\AI\Domain\Persona\PersonaTaste;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiProfileSettings;
use Modules\AI\Support\RandomSource;

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
            'sessionsPerDay' => self::sessionsPerDay($profile->activity_band, PersonaTaste::fromSeed((int) $profile->random_seed, app(RandomSource::class))->diligence),
        ]);
    }

    /**
     * How often each kind of player looks at the account in a day.
     *
     * This is the player's pace, not a game rule: a casual player opens the game a
     * couple of times around work, and a hardcore one lives in it. The figure is the
     * target the waits are drawn around, not the visits a month actually
     * contains: the longest waits are spent asleep, so a persona lands somewhat
     * above its target, and the targets are set from that measurement.
     *
     * Diligence is the account's own: one miner is not the next miner, so a lazy
     * one plays the floor of its archetype band and a diligent one the ceiling.
     */
    private static function sessionsPerDay(AiActivityBand|null $band, float $diligence): int
    {
        [$fewest, $most] = self::presenceBand($band);

        return $fewest + (int) round(($most - $fewest) * $diligence);
    }

    /**
     * The visits a day each pace of player makes, from the analogue benchmark the plan records. Pace is
     * the account's own activity band, not its archetype, so a casual fleeter and a hardcore miner both
     * exist; diligence places the account inside its band. An account with no stated pace plays Regular.
     *
     * @return array{0: int, 1: int}
     */
    private static function presenceBand(AiActivityBand|null $band): array
    {
        return match ($band ?? AiActivityBand::Regular) {
            AiActivityBand::Casual => [1, 3],
            AiActivityBand::Regular => [3, 7],
            AiActivityBand::Active => [7, 12],
            AiActivityBand::Hardcore => [12, 18],
        };
    }
}
