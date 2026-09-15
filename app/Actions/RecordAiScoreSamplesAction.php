<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Review\AiScoreSampleRun;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiScoreSample;
use Modules\AI\Support\AiClock;
use OGame\Models\Highscore;

/**
 * Records one hour of the cohort's public score, so the growth curve exists as a series.
 *
 * The host keeps current points and no history, so the curve the authenticity signals are read
 * from can only come from samples the module takes itself. The pass writes one row per enabled
 * account per hour and skips an account whose score the host has not written yet.
 *
 * It reads the host's numbers rather than computing them: a sample is what a neighbour could read
 * from the host at that hour, which is what makes it evidence about the account instead of a
 * module opinion about it. Nothing here is on a session's path, so a slow or missing sample costs
 * a data point and never an account's play. How long a sample is kept is the retention owner's
 * decision, not a second window declared here.
 */
class RecordAiScoreSamplesAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(): AiScoreSampleRun
    {
        $now = $this->clock->now();
        // Truncated to the hour so a rerun inside one hour updates that hour's row rather than
        // adding a second one: the series is one row per account per hour whatever cadence the
        // operator runs the pass at.
        $sampledAt = $now->startOfHour();
        $sampled = 0;
        $skipped = 0;

        foreach (AiProfile::query()->where('enabled', true)->orderBy('player_id')->pluck('player_id') as $playerId) {
            $score = Highscore::query()->where('player_id', $playerId)->first();

            // A young universe has no score row for an account until the host's own highscore
            // pass writes one, so a skip is the expected state rather than a failure.
            if ($score === null) {
                $skipped++;
                continue;
            }

            AiScoreSample::query()->updateOrCreate(
                ['player_id' => $playerId, 'sampled_at' => $sampledAt],
                [
                    'general' => (int) $score->general,
                    'economy' => (int) $score->economy,
                    'research' => (int) $score->research,
                    'military_built' => (int) $score->military_built,
                    'military_destroyed' => (int) $score->military_destroyed,
                    'military_lost' => (int) $score->military_lost,
                    'general_rank' => $score->general_rank > 0 ? (int) $score->general_rank : null,
                ],
            );
            $sampled++;
        }

        return app()->makeWith(AiScoreSampleRun::class, [
            'sampled' => $sampled,
            'skipped' => $skipped,
            'sampledAt' => $sampledAt,
        ]);
    }
}
