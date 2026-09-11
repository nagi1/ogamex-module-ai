<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Cognition\AffectAppraisal;
use Modules\AI\Domain\Cognition\ObservedStimulus;

interface AffectEngine
{
    public function appraiseObservedEvent(ObservedStimulus $stimulus): AffectAppraisal;
}
