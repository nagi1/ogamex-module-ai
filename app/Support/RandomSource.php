<?php

namespace Modules\AI\Support;

interface RandomSource
{
    /** Returns a stable value from zero (inclusive) to one (exclusive). */
    public function unitInterval(int $seed, string $context): float;
}
