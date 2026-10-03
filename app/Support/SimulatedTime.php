<?php

namespace Modules\AI\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\Date;

/**
 * The one switch that moves game time without waiting for it.
 *
 * OGame is calculation over timestamps: production, queues, flights and the module's routines all read
 * "now" from Carbon (the host's `now()` and `Date::now()`, the module's `AiClock`). Freezing or moving
 * Carbon's test instant therefore moves the whole game at once, with no wall-clock hour in between.
 * Every Carbon flavour is set together because each keeps its own test instant.
 *
 * DEV TOOLING for simulation and tests: `ai:sim` steps this clock through simulated hours, the
 * `AI_SIM_NOW` environment variable freezes any script or tinker run at an instant, and tests use
 * it to stand at a known hour. Nothing in a live request path calls it.
 */
final class SimulatedTime
{
    /** Freeze every Carbon flavour at one instant and return it. */
    public static function freezeAt(CarbonInterface|string $instant): CarbonImmutable
    {
        $at = CarbonImmutable::parse($instant);

        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
        IlluminateCarbon::setTestNow($at);
        Date::setTestNow($at);

        return $at;
    }

    /** Move the frozen clock forward (negative values move it back). */
    public static function advance(int $seconds): CarbonImmutable
    {
        return self::freezeAt(self::now()->addSeconds($seconds));
    }

    /** The instant the game currently reads, simulated or real. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /** Back to the wall clock. */
    public static function release(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        IlluminateCarbon::setTestNow();
        Date::setTestNow();
    }

    public static function isFrozen(): bool
    {
        return CarbonImmutable::hasTestNow();
    }

    /**
     * Freeze at the instant a process was asked to stand at: `AI_SIM_NOW=2026-10-05T12:00:00Z`.
     * An empty or missing variable leaves the wall clock alone.
     */
    public static function freezeFromEnvironment(): void
    {
        $instant = getenv('AI_SIM_NOW');

        if ($instant === false || trim($instant) === '') {
            return;
        }

        self::freezeAt(trim($instant));
    }
}
