<?php

namespace Modules\AI\Support;

use Carbon\CarbonImmutable;

class SystemAiClock implements AiClock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
