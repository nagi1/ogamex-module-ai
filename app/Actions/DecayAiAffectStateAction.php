<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Models\AiAffectState;

class DecayAiAffectStateAction
{
    private const DailyDecay = 0.25;

    public function handle(AiAffectState $state, CarbonImmutable $now): AiAffectState
    {
        if ($state->updated_for->greaterThanOrEqualTo($now)) {
            return $state;
        }

        $elapsedDays = $state->updated_for->diffInSeconds($now) / 86_400;
        $intensity = max(0, (float) $state->intensity - ($elapsedDays * self::DailyDecay));

        $state->update([
            'intensity' => $intensity,
            'updated_for' => $now,
            'revision' => $state->revision + 1,
        ]);

        return $state->refresh();
    }
}
