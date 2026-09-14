<?php

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The host's own round-the-clock signal flags a player whose fleet departures
 * span eighteen or more distinct hours of the day inside the lookback window
 * (`ServerAdministrationController`, `bot_detection_active_hours`). A routine is
 * only safe if it stays a whole hour below that, which is what these tests
 * measure over a simulated month of scheduled sessions.
 */
const AI_CADENCE_FLAGGED_HOURS = 18;

const AI_CADENCE_SAFE_HOURS = 17;

const AI_CADENCE_TIMEZONE = 'Europe/Berlin';

const AI_CADENCE_DAYS = 21;

/** Four weeks is where neighbours write an account off (H3). */
const AI_CADENCE_MAXIMUM_ABSENCE_DAYS = 28;

/**
 * Absences are yearly phenomena, so a single account-year can hold none: the
 * bands are rates over a population, and five years of one account is the
 * shortest run in which every band is expected to appear.
 */
const AI_CADENCE_ABSENCE_DAYS = 1825;

test('a persona never reaches the hours the host flags', function (AiArchetype $archetype) {
    $windows = aiCadenceWindows($archetype);
    $hours = aiCadenceWindowHours($windows);

    expect(aiCadenceDistinctHours($windows, $hours, 7))->toBeLessThanOrEqual(AI_CADENCE_SAFE_HOURS)
        ->and(AI_CADENCE_SAFE_HOURS)->toBeLessThan(AI_CADENCE_FLAGGED_HOURS);
})->with(aiCadenceArchetypes());

test('every day contains a dark period of at least six hours', function (AiArchetype $archetype) {
    $uncovered = aiCadenceDatesWithoutDarkPeriod(aiCadenceWindows($archetype));

    expect($uncovered)->toBe([]);
})->with(aiCadenceArchetypes());

test('a persona is present as often as its own kind of player is', function (AiArchetype $archetype, int $fewest, int $most) {
    $occurrences = aiCadenceDailyOccurrences(aiCadenceWindows($archetype));
    $average = aiCadenceAverage($occurrences);

    expect($average)->toBeGreaterThanOrEqual($fewest)
        ->and($average)->toBeLessThanOrEqual($most);
})->with(aiCadencePresence());

test('a fleeter looks in more often than a casual player', function () {
    $casual = aiCadenceDailyOccurrences(aiCadenceWindows(AiArchetype::Casual));
    $fleeter = aiCadenceDailyOccurrences(aiCadenceWindows(AiArchetype::Fleeter));

    expect(aiCadenceAverage($fleeter))->toBeGreaterThan(aiCadenceAverage($casual) * 1.5);
});

test('the account is away the way a person is away', function () {
    $days = aiCadenceDailyOccurrences(aiCadenceWindows(AiArchetype::Miner, AI_CADENCE_ABSENCE_DAYS));
    $absences = aiCadenceAbsences($days);
    $single = array_count_values($absences)[1] ?? 0;

    // H3's bands: one to three single days a month, one three-to-seven-day gap a
    // quarter, one gap of a week or more a year, and nothing in between and
    // nothing anywhere near the four weeks that writes an account off.
    expect(max($absences))->toBeLessThanOrEqual(AI_CADENCE_MAXIMUM_ABSENCE_DAYS)
        ->and($single / AI_CADENCE_ABSENCE_DAYS)->toBeGreaterThanOrEqual(1 / 30)
        ->and($single / AI_CADENCE_ABSENCE_DAYS)->toBeLessThanOrEqual(3 / 30)
        ->and(aiCadenceAbsencesBetween($absences, 3, 7))->toBeGreaterThanOrEqual(5)
        ->and(aiCadenceAbsencesBetween($absences, 7, 365))->toBeGreaterThanOrEqual(1)
        ->and(aiCadenceAbsencesBetween($absences, 2, 2))->toBe(0);
});

test('session length is heavy-tailed, not a constant', function () {
    $lengths = aiCadenceSessionMinutes(aiCadenceWindows(AiArchetype::Miner));
    sort($lengths);
    $median = $lengths[intdiv(count($lengths), 2)];
    $longest = end($lengths);

    // A constant session length would keep the mean at the median; human
    // sessions are mostly short visits with the occasional long evening block.
    expect(aiCadenceAverage($lengths))->toBeGreaterThan($median)
        ->and($longest)->toBeGreaterThanOrEqual($median * 3);
});

/** @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}> */
function aiCadenceWindows(AiArchetype $archetype, int $days = AI_CADENCE_DAYS): array
{
    $profile = aiCadenceProfile($archetype);
    $planner = app(SessionPlanner::class);
    $start = CarbonImmutable::create(2026, 9, 1, 0, 0, 0, 'UTC');
    $end = $start->addDays($days);
    // The session starts when the previous plan said it would, which is exactly
    // the chain the schedule runs in production.
    $now = $planner->plan($profile, $start, 1)->nextDueAt;
    $windows = [];

    for ($generation = 1; $now->lessThan($end) && $generation <= 100_000; $generation++) {
        $plan = $planner->plan($profile, $now, $generation);
        $windows[] = [$now, $plan->sessionEndsAt];
        $now = $plan->nextDueAt;
    }

    expect($windows)->not->toBeEmpty();

    return $windows;
}

function aiCadenceProfile(AiArchetype $archetype): AiProfile
{
    // The planner reads a profile, never host state, so the account payload is
    // built rather than persisted: one account per archetype would otherwise
    // collide across the datasets of these tests.
    return app()->makeWith(AiProfile::class, ['attributes' => [
        'player_id' => 900_000 + $archetype->value,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 4_200 + $archetype->value,
    ]]);
}

/** @return array<string, array{0: AiArchetype}> */
function aiCadenceArchetypes(): array
{
    return [
        'miner' => [AiArchetype::Miner],
        'turtle' => [AiArchetype::Turtle],
        'fleeter' => [AiArchetype::Fleeter],
        'trader' => [AiArchetype::Trader],
        'casual' => [AiArchetype::Casual],
    ];
}

/**
 * The visits a day each kind of player makes, from the analogue benchmark the
 * plan records: a casual player two or three, a regular one four to eight, one
 * hardcore persona ten to sixteen.
 *
 * @return array<string, array{0: AiArchetype, 1: int, 2: int}>
 */
function aiCadencePresence(): array
{
    return [
        'casual' => [AiArchetype::Casual, 2, 3],
        'trader' => [AiArchetype::Trader, 4, 8],
        'turtle' => [AiArchetype::Turtle, 4, 8],
        'miner' => [AiArchetype::Miner, 4, 8],
        'fleeter' => [AiArchetype::Fleeter, 10, 16],
    ];
}

/** @param array<int, array{0: CarbonImmutable, 1: CarbonImmutable}> $windows */
function aiCadenceWindowHours(array $windows): array
{
    $hours = [];
    foreach ($windows as $index => [$start, $end]) {
        $set = [];
        for ($minute = 0, $length = (int) $start->diffInMinutes($end); $minute <= $length; $minute++) {
            $set[aiCadenceHour($start->addMinutes($minute))] = true;
        }
        $hours[$index] = array_keys($set);
    }

    return $hours;
}

/** The distinct hours of the day the host would count, over the widest week. */
function aiCadenceDistinctHours(array $windows, array $hours, int $days): int
{
    $widest = 0;
    foreach ($windows as $index => [$start]) {
        $limit = $start->addDays($days);
        $union = [];
        foreach (array_slice($windows, $index, preserve_keys: true) as $other => [$otherStart]) {
            if ($otherStart->greaterThanOrEqualTo($limit)) {
                break;
            }
            foreach ($hours[$other] as $hour) {
                $union[$hour] = true;
            }
        }
        $widest = max($widest, count($union));
    }

    return $widest;
}

function aiCadenceHour(CarbonImmutable $moment): int
{
    return (int) floor(($moment->getTimestamp() % 86400) / 3600);
}

/** @param array<int, array{0: CarbonImmutable, 1: CarbonImmutable}> $windows */
function aiCadenceDailyOccurrences(array $windows): array
{
    $occurrences = [];
    foreach ($windows as [$start]) {
        $date = $start->setTimezone(AI_CADENCE_TIMEZONE)->toDateString();
        $occurrences[$date] = ($occurrences[$date] ?? 0) + 1;
    }

    return $occurrences;
}

/**
 * The local days that no long silence covers. A night is the six hours or more
 * the account is away between two sessions, and it is what the host's own rule
 * asks for; the run's first day is partial by construction and is not one of the
 * days under test.
 *
 * @param array<int, array{0: CarbonImmutable, 1: CarbonImmutable}> $windows
 * @return array<int, string>
 */
function aiCadenceDatesWithoutDarkPeriod(array $windows): array
{
    $covered = [];
    for ($index = 1; $index < count($windows); $index++) {
        [$from, $to] = [$windows[$index - 1][1], $windows[$index][0]];
        if ($from->diffInMinutes($to) < 360) {
            continue;
        }
        for ($day = $from->setTimezone(AI_CADENCE_TIMEZONE)->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $covered[$day->toDateString()] = true;
        }
    }

    $dates = aiCadenceDailyOccurrences($windows);
    array_shift($dates);

    return array_values(array_diff_key($dates, $covered));
}

/** @param array<int, array{0: CarbonImmutable, 1: CarbonImmutable}> $windows */
function aiCadenceSessionMinutes(array $windows): array
{
    return array_map(static fn (array $window): int => (int) $window[0]->diffInMinutes($window[1]), $windows);
}

/**
 * The runs of days the account was away for, one entry per run.
 *
 * A run is the number of local days between two days that have a session, so a
 * single absent day is one entry of one day.
 *
 * @param array<string, int> $occurrences
 * @return array<int, int>
 */
function aiCadenceAbsences(array $occurrences): array
{
    $dates = array_map(
        static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, AI_CADENCE_TIMEZONE)->startOfDay(),
        array_keys($occurrences),
    );
    $absences = [];
    for ($index = 1; $index < count($dates); $index++) {
        $away = (int) $dates[$index - 1]->diffInDays($dates[$index]) - 1;
        if ($away === 0) {
            continue;
        }
        $absences[] = $away;
    }

    return $absences;
}

/** @param array<int, int> $absences */
function aiCadenceAbsencesBetween(array $absences, int $fewest, int $most): int
{
    return count(array_filter($absences, static fn (int $days): bool => $days >= $fewest && $days <= $most));
}

function aiCadenceAverage(array $values): float
{
    return array_sum($values) / count($values);
}
