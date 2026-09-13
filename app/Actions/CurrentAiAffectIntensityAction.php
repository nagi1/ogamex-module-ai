<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Models\AiAffectState;

/**
 * The intensity this AI currently holds for one emotion.
 *
 * Decay is persisted here rather than recomputed on every read, so the stored value and the
 * answered value cannot drift apart and the existing decay rule stays the only implementation
 * of it. An AI with no recorded state answers zero rather than raising, because "this AI is not
 * angry" is a fact about it and not an error.
 */
class CurrentAiAffectIntensityAction
{
    public function handle(int $playerId, AiAffectEmotion $emotion, CarbonImmutable $at): float
    {
        $state = AiAffectState::query()
            ->where('player_id', $playerId)
            ->where('emotion', $emotion)
            ->first();

        if ($state === null) {
            return 0.0;
        }

        return (float) app(DecayAiAffectStateAction::class)->handle($state, $at)->intensity;
    }
}
