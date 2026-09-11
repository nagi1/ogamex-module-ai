<?php

namespace Modules\AI\Domain\Cognition;

use Modules\AI\Enums\AiAffectEmotion;

readonly class AffectAppraisal
{
    public function __construct(
        public AiAffectEmotion $emotion,
        public float $intensity,
    ) {
    }
}
