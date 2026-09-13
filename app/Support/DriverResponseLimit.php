<?php

namespace Modules\AI\Support;

/**
 * Bounds the body the module will read from an optional cognition driver.
 *
 * Every supported driver is a module-owned service that answers with a small,
 * fixed-shape object, so a body above this bound is a contract deviation rather than
 * data worth interpreting. The bound is module policy instead of a driver property,
 * which is why one setting covers every driver: a driver must not be able to widen the
 * module's input surface by answering with more than the module agreed to read.
 *
 * The bound is applied to the body the module has already received, so it limits what
 * is accepted and parsed, not what the driver chooses to transmit. A sidecar flooding
 * the socket is contained by the request timeout, not by this check.
 */
class DriverResponseLimit
{
    private const DEFAULT_MAXIMUM_BYTES = 262_144;

    public function withinLimit(string $body): bool
    {
        return strlen($body) <= $this->maximumBytes();
    }

    public function maximumBytes(): int
    {
        return max(1, (int) config('ai.cognition.payload.maximum_response_bytes', self::DEFAULT_MAXIMUM_BYTES));
    }
}
