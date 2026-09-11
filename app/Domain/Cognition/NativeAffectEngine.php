<?php

namespace Modules\AI\Domain\Cognition;

use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;

class NativeAffectEngine implements AffectEngine
{
    public function appraiseObservedEvent(ObservedStimulus $stimulus): AffectAppraisal
    {
        if ($stimulus->aid > $stimulus->harm) {
            return app()->makeWith(AffectAppraisal::class, [
                'emotion' => AiAffectEmotion::Gratitude,
                'intensity' => $this->bounded($stimulus->aid * (1 + $stimulus->relationshipTrust)),
            ]);
        }

        if ($stimulus->threat > $stimulus->harm) {
            return app()->makeWith(AffectAppraisal::class, [
                'emotion' => AiAffectEmotion::Fear,
                'intensity' => $this->bounded($stimulus->threat),
            ]);
        }

        $vengeanceWeight = match ($stimulus->archetype) {
            AiArchetype::Fleeter => 1,
            AiArchetype::Turtle => 0.4,
            AiArchetype::Miner, AiArchetype::Trader, AiArchetype::Casual => 0.2,
        };

        return app()->makeWith(AffectAppraisal::class, [
            'emotion' => AiAffectEmotion::Anger,
            'intensity' => $this->bounded($stimulus->harm * $vengeanceWeight * (1 - $stimulus->relationshipTrust)),
        ]);
    }

    private function bounded(float $intensity): float
    {
        return min(1, max(0, $intensity));
    }
}
