<?php

namespace Modules\AI\Support;

/**
 * Hash-derived randomness avoids global PRNG state, so workers handling
 * different players cannot influence each other's decision trace.
 */
class SeededRandomSource implements RandomSource
{
    private const HEX_DIGITS = 8;

    private const MAX_UNSIGNED_INT_32 = 4_294_967_296;

    public function unitInterval(int $seed, string $context): float
    {
        $hex = substr(hash('sha256', $seed . ':' . $context), 0, self::HEX_DIGITS);

        return hexdec($hex) / self::MAX_UNSIGNED_INT_32;
    }
}
