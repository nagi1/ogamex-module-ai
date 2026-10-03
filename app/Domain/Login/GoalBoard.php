<?php

namespace Modules\AI\Domain\Login;

use Carbon\CarbonInterface;
use Modules\AI\Models\AiGoal;
use Modules\AI\Support\AiClock;
use Symfony\Component\Yaml\Yaml;

/**
 * The account's own objective for the logins ahead (architecture step 4).
 *
 * A login that re-decides from scratch has intentions no longer than one session, so "save up for a
 * colony ship" cannot exist. This is the one place such an intention is written and read: committing
 * the same goal again re-affirms it -- the target, the progress and the window follow the newest
 * decision, the goal does not duplicate -- and a goal is held only until it is met or clearly
 * failing, so a rung the universe will not let the account reach stops being re-decided as the same
 * failing intent on every login (hysteresis).
 *
 * A goal is a free name for an intention, never a list of objects, and no number that decides one
 * lives here: the objective and the window come from `resources/behavior/goals.yaml`.
 */
class GoalBoard
{
    /** Module root relative, so the goal's own numbers are loaded by name (Gate 1). */
    private const POLICY = '/resources/behavior/goals.yaml';

    /** @var array<string, mixed>|null */
    private static ?array $policy = null;

    public function __construct(private readonly AiClock $clock)
    {
    }

    /** The intention an account with no live goal takes up, named rather than listed. */
    public function objective(): string
    {
        return (string) ($this->policy()['objective'] ?? '');
    }

    /**
     * Write the goal, or re-affirm the one the account already holds. The window is the caller's when
     * it names one, otherwise the data file's.
     *
     * A target the account has already reached is not a commitment: the row it may have held is
     * abandoned and nothing is written, so the day it got there leaves no stale intention behind for
     * the next login to re-affirm.
     */
    public function commit(int $playerId, string $goal, int $target, ?CarbonInterface $abandonAfter = null, int $progress = 0): ?AiGoal
    {
        if ($goal === '') {
            return null;
        }

        if ($progress >= $target) {
            $this->abandon($playerId, $goal);

            return null;
        }

        $held = AiGoal::query()->firstOrNew(['player_id' => $playerId, 'goal' => $goal]);
        $held->target = $target;
        $held->progress = $progress;
        $held->abandon_after = $abandonAfter ?? $this->clock->now()->addHours($this->windowHours());
        // The day the account took the goal up does not move when it is re-affirmed: a rung it has
        // worked on for two logins still reads as one intention, not as two.
        $held->started_at ??= $this->clock->now();
        $held->save();

        return $held;
    }

    /**
     * The account's live goals, oldest first. A goal past its window is abandoned here rather than
     * carried: the account tried and did not get there, and the next login takes up an objective
     * afresh instead of re-affirming a failing one.
     *
     * @return list<AiGoal>
     */
    public function active(int $playerId): array
    {
        $now = $this->clock->now();
        $live = [];

        foreach (AiGoal::query()->where('player_id', $playerId)->orderBy('started_at')->orderBy('id')->get() as $goal) {
            if ($goal->abandon_after->lessThanOrEqualTo($now)) {
                $goal->delete();

                continue;
            }

            $live[] = $goal;
        }

        return $live;
    }

    /** Give one goal up: it is met, or its window has passed. */
    private function abandon(int $playerId, string $goal): void
    {
        AiGoal::query()->where('player_id', $playerId)->where('goal', $goal)->delete();
    }

    /** How long a goal holds before it is abandoned, from the data file: no number lives in this class. */
    private function windowHours(): int
    {
        $value = $this->policy()['abandon_after_hours'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        if (self::$policy !== null) {
            return self::$policy;
        }

        $path = dirname(__DIR__, 3) . self::POLICY;
        $parsed = is_file($path) ? Yaml::parseFile($path) : [];

        return self::$policy = is_array($parsed) ? $parsed : [];
    }
}
