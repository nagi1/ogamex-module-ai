<?php

namespace Modules\AI\Domain\Cognition;

use Modules\AI\Enums\AiAffectEmotion;

readonly class AffectAppraisal
{
    public function __construct(
        public AiAffectEmotion $emotion,
        public float $intensity,
        // Driver evidence, present only when an external affect driver actually answered.
        // The module's taxonomy stays on `emotion`/`intensity`; these carry what the driver
        // added — its valence and its own mapped judgement — for a hybrid consumer.
        public float|null $mood = null,
        public AiAffectEmotion|null $driverEmotion = null,
        public float|null $driverIntensity = null,
    ) {
    }
}
