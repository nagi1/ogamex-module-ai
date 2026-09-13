<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Models\AiAffectState;

/**
 * Advances this AI's transient emotional state for one emotion.
 *
 * This is deliberately not the same record as an emotional episode. An episode states that
 * something significant happened and is never rewritten; this value is the running intensity
 * that fades, and it is what a later decision reads as "current anger".
 *
 * Decay is applied here, before the new intensity is added, so an event arriving after a quiet
 * period starts from the state the AI would actually be in. That keeps decay honest without a
 * scheduler, a queue job or a background sweep, and it stays deterministic because the caller
 * supplies the instant rather than this action reading a clock.
 */
class UpdateAiAffectStateAction
{
    /** Intensity that fades per elapsed day. */
    private const DAILY_DECAY = 0.25;

    private const MAXIMUM_INTENSITY = 1.0;

    private const SECONDS_PER_DAY = 86_400;

    public function handle(int $playerId, AiAffectEmotion $emotion, float $intensity, CarbonImmutable $at): AiAffectState
    {
        $state = AiAffectState::query()->firstOrNew([
            'player_id' => $playerId,
            'emotion' => $emotion,
        ]);

        $state->fill([
            'intensity' => min(self::MAXIMUM_INTENSITY, max(0, $this->decayed($state, $at) + $intensity)),
            'updated_for' => $this->latest($state, $at),
            'revision' => ((int) $state->revision) + 1,
        ])->save();

        return $state->refresh();
    }

    private function decayed(AiAffectState $state, CarbonImmutable $at): float
    {
        if (!$state->exists) {
            return 0.0;
        }

        return max(0, (float) $state->intensity - ($this->elapsedDays($state, $at) * self::DAILY_DECAY));
    }

    /**
     * A late event must not move the stamp backwards. The next update would then compute a
     * negative elapsed period and raise the intensity instead of fading it.
     */
    private function latest(AiAffectState $state, CarbonImmutable $at): CarbonImmutable
    {
        if (!$state->exists) {
            return $at;
        }

        $updatedFor = CarbonImmutable::instance($state->updated_for);

        return $updatedFor->greaterThan($at) ? $updatedFor : $at;
    }

    private function elapsedDays(AiAffectState $state, CarbonImmutable $at): float
    {
        $updatedFor = CarbonImmutable::instance($state->updated_for);

        if ($updatedFor->greaterThanOrEqualTo($at)) {
            return 0.0;
        }

        return $updatedFor->diffInSeconds($at) / self::SECONDS_PER_DAY;
    }
}
