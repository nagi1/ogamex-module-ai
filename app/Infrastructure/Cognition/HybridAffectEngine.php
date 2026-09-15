<?php

namespace Modules\AI\Infrastructure\Cognition;

use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Domain\Cognition\AffectAppraisal;
use Modules\AI\Domain\Cognition\ObservedStimulus;

/**
 * The hybrid affect engine: native always answers, and the FAtiMA driver adds its own
 * judgement alongside it.
 *
 * The module's three-emotion taxonomy stays authoritative, so the native emotion and
 * intensity are the appraisal; the driver contributes its valence (mood) and its own
 * mapped emotion and intensity as evidence. When the driver degrades it returns a plain
 * native appraisal, so the evidence fields stay null and the hybrid result is exactly the
 * native answer.
 */
class HybridAffectEngine implements AffectEngine
{
    public function __construct(
        private readonly AffectEngine $native,
        private readonly AffectEngine $driver,
    ) {
    }

    public function appraiseObservedEvent(ObservedStimulus $stimulus): AffectAppraisal
    {
        $native = $this->native->appraiseObservedEvent($stimulus);
        $driver = $this->driver->appraiseObservedEvent($stimulus);

        return app()->makeWith(AffectAppraisal::class, [
            'emotion' => $native->emotion,
            'intensity' => $native->intensity,
            'mood' => $driver->mood,
            'driverEmotion' => $driver->driverEmotion,
            'driverIntensity' => $driver->driverIntensity,
        ]);
    }
}
