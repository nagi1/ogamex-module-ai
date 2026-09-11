<?php

namespace Modules\AI\Support;

use Carbon\CarbonImmutable;

interface AiClock
{
    public function now(): CarbonImmutable;
}
