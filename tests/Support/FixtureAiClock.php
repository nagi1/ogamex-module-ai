<?php

namespace Modules\AI\Tests\Support;

use Carbon\CarbonImmutable;
use Modules\AI\Support\AiClock;

/** Explicit, container-resolved clock fixture for deterministic boundary inputs. */
final class FixtureAiClock implements AiClock
{
    public function __construct(private readonly CarbonImmutable $now)
    {
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }
}
