<?php

namespace Modules\AI\Domain\Routine;

use Carbon\CarbonImmutable;

readonly class SessionPlan
{
    public function __construct(
        public CarbonImmutable $sessionEndsAt,
        public CarbonImmutable $nextDueAt,
    ) {
    }
}
