<?php

namespace Modules\AI\Domain\Routine;

use Carbon\CarbonImmutable;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;

/**
 * Places each session inside the account's own waking day.
 *
 * The host flags a player whose fleet departures span eighteen or more distinct
 * hours of the day (`ServerAdministrationController`, round-the-clock signal),
 * so the routine needs a dark period the account never acts in. Every account
 * keeps a nine-hour core dark period, long enough to block eight whole hours of
 * every day whatever its clock offset, and each day's drift can only extend it:
 * bed and wake times move the way a person's do, and the guarantee never
 * shrinks (H1, H5).
 */
class SessionPlanner
{
    private const DAY_MINUTES = 1440;

    /** The dark period every account keeps, whatever its wake time. */
    private const CORE_DARK_MINUTES = 540;

    /** Early bird to night owl: the span the account's wake time is drawn from. */
    private const EARLIEST_WAKE_MINUTE = 330;

    private const WAKE_SPREAD_MINUTES = 240;

    /** How far one day's bed and wake time may slip past the account's core. */
    private const DRIFT_MINUTES = 30;

    /** Weibull shape below one: most waits are short and a few are long. */
    private const TAIL_SHAPE = 0.8;

    /** The mean of that shape, so a wait's own average is the wait it was given. */
    private const TAIL_MEAN = 1.133;

    /** Keeps the drawn unit away from zero, where its logarithm diverges. */
    private const MINIMUM_UNIT = 1.0E-9;

    public function __construct(private RandomSource $randomSource)
    {
    }

    public function plan(AiProfile $profile, CarbonImmutable $now, int $generation): SessionPlan
    {
        $routine = RoutineProfile::fromAiProfile($profile);
        $local = $now->setTimezone($routine->timezone);
        [$wake, $bed] = $this->wakingWindow($profile, $local);
        $wakingMinutes = (int) $wake->diffInMinutes($bed);
        $step = max($routine->sessionMinutes + 1, intdiv($wakingMinutes, $routine->sessionsPerDay));
        $waitBase = max(1, $step - $routine->sessionMinutes);

        // A wait skips the dark period rather than the day: one unlucky draw
        // must not park the account for a week and read as abandoned.
        $sessionEndsAt = $this->earliest(
            $local->addMinutes($this->wait($profile, $routine->sessionMinutes, 'length:' . $generation)),
            $bed,
        );
        $wait = min($this->wait($profile, $waitBase, 'wait:' . $generation), $wakingMinutes);
        $candidate = $sessionEndsAt->addMinutes($wait);
        [$wake, $bed] = $this->wakingWindow($profile, $candidate);
        // The account looks in again when it wakes up, so the wait that ran into
        // the dark period is spent: reusing it would skip the day it just woke
        // into, and an absence nobody planned reads as an abandoned account.
        $nextDueAt = $candidate->lessThan($wake)
            ? $this->firstSessionOf($wake, $bed, min($this->wait($profile, $waitBase, 'wake:' . $generation), $step))
            : $candidate;

        return app()->makeWith(SessionPlan::class, [
            'sessionEndsAt' => $sessionEndsAt,
            'nextDueAt' => $nextDueAt,
        ]);
    }

    /**
     * The waking window that contains the moment, or the next one when the
     * moment falls inside a dark period.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function wakingWindow(AiProfile $profile, CarbonImmutable $local): array
    {
        $day = $local->startOfDay();
        [$wake, $bed] = $this->dayWindow($profile, $day);

        // Before today's wake the account is still in the dark period the
        // previous day ended with, and once it reaches today's bed the window
        // containing it is the next day's.
        if ($local->lessThan($wake)) {
            $day = $day->subDay();
            [$wake, $bed] = $this->dayWindow($profile, $day);
        }

        if ($local->greaterThanOrEqualTo($bed)) {
            $day = $day->addDay();
            [$wake, $bed] = $this->dayWindow($profile, $day);
        }

        return [$wake, $bed];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dayWindow(AiProfile $profile, CarbonImmutable $day): array
    {
        $wakeMinute = $this->coreWakeMinute($profile) + $this->drift($profile, $day, 'wake');
        $bedMinute = $wakeMinute + self::DAY_MINUTES - self::CORE_DARK_MINUTES - $this->drift($profile, $day, 'bed');

        return [$day->addMinutes($wakeMinute), $day->addMinutes($bedMinute)];
    }

    /** The first session of a waking day: after waking, but never past its bed. */
    private function firstSessionOf(CarbonImmutable $wake, CarbonImmutable $bed, int $wait): CarbonImmutable
    {
        $start = $wake->addMinutes($wait);

        return $start->lessThan($bed) ? $start : $bed->subMinute();
    }

    private function coreWakeMinute(AiProfile $profile): int
    {
        return self::EARLIEST_WAKE_MINUTE + $this->scaled($profile, 'routine:anchor', self::WAKE_SPREAD_MINUTES);
    }

    /** How far this day's bed or wake time slips past the account's core. */
    private function drift(AiProfile $profile, CarbonImmutable $day, string $edge): int
    {
        return $this->scaled($profile, 'routine:' . $edge . ':' . $day->toDateString(), self::DRIFT_MINUTES);
    }

    /**
     * A Weibull draw, whose shape below one gives the heavy tail human activity
     * has: many short waits and the occasional long one. Jitter on a fixed
     * period would leave the period itself readable (H2, H5).
     */
    private function wait(AiProfile $profile, int $base, string $context): int
    {
        $unit = max($this->randomSource->unitInterval($profile->random_seed, $context), self::MINIMUM_UNIT);
        $drawn = ($base / self::TAIL_MEAN) * (-log($unit)) ** (1 / self::TAIL_SHAPE);

        // Never zero: the account does not run two sessions in the same minute.
        return max(1, (int) round($drawn));
    }

    private function scaled(AiProfile $profile, string $context, int $ceiling): int
    {
        return (int) round($this->randomSource->unitInterval($profile->random_seed, $context) * $ceiling);
    }

    private function earliest(CarbonImmutable $first, CarbonImmutable $second): CarbonImmutable
    {
        return $first->lessThan($second) ? $first : $second;
    }
}
