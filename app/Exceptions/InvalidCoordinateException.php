<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a string is not a galaxy:system:position reference.
 *
 * The type holds no numeric bounds, so the message names the value that failed the shape rather than
 * a range it fell outside of.
 */
final class InvalidCoordinateException extends InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('"%s" is not a galaxy:system:position coordinate.', $value));
    }
}
