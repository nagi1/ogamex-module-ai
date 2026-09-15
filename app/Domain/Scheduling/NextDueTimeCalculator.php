<?php

namespace Modules\AI\Domain\Scheduling;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Routine\SessionPlan;

class NextDueTimeCalculator
{
    public function fromSession(SessionPlan $sessionPlan, CarbonImmutable $now): CarbonImmutable
    {
        $intervalSeconds = (int) config('ai.population.session_interval_seconds', 0);
        if ($intervalSeconds > 0) {
            return $now->addSeconds($intervalSeconds);
        }

        return $sessionPlan->nextDueAt->greaterThan($now) ? $sessionPlan->nextDueAt : $now->addMinute();
    }
}
