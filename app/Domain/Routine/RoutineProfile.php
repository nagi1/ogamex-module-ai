<?php

namespace Modules\AI\Domain\Routine;

use DateTimeZone;
use Exception;
use Modules\AI\Domain\Persona\PersonaTaste;
use Modules\AI\Enums\AiArchetype;
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
            'sessionsPerDay' => self::sessionsPerDay($profile->archetype, PersonaTaste::fromSeed((int) $profile->random_seed, app(RandomSource::class))->diligence),
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
     *
     * Diligence is the account's own: one miner is not the next miner, so a lazy
     * one plays the floor of its archetype band and a diligent one the ceiling.
     */
    private static function sessionsPerDay(AiArchetype $archetype, float $diligence): int
    {
        [$fewest, $most] = self::presenceBand($archetype);

        return $fewest + (int) round(($most - $fewest) * $diligence);
    }

    /**
     * The visits a day each kind of player makes, from the analogue benchmark the
     * plan records. Diligence places the account inside its own band.
     *
     * @return array{0: int, 1: int}
     */
    private static function presenceBand(AiArchetype $archetype): array
    {
        return match ($archetype) {
            AiArchetype::Casual => [2, 3],
            AiArchetype::Trader => [4, 8],
            AiArchetype::Turtle => [4, 8],
            AiArchetype::Miner => [4, 8],
            AiArchetype::Fleeter => [10, 16],
        };
    }
}
