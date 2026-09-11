<?php

namespace Modules\AI\Domain\Scheduling;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Routine\SessionPlan;

class NextDueTimeCalculator
{
    public function fromSession(SessionPlan $sessionPlan, CarbonImmutable $now): CarbonImmutable
    {
        return $sessionPlan->nextDueAt->greaterThan($now) ? $sessionPlan->nextDueAt : $now->addMinute();
    }
}
