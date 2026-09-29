<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Position;

use InvalidArgumentException;

/**
 * A solar-system position: an ordinal confined to the bound the host supplies.
 *
 * The host owns every universe fact about a slot, so only the ordinal is kept here —
 * positions can be named and compared without re-deriving any slot rule.
 */
final readonly class Position
{
    private function __construct(
        private int $ordinal,
    ) {
    }

    /**
     * @param  mixed  $ordinal  the ordinal as the host holds it: an int, or that integer's literal string form
     */
    public static function fromOrdinal(mixed $ordinal, int $lowerBound, int $upperBound): self
    {
        $value = self::asOrdinal($ordinal);

        // "Negative ordinals throw" is stated without qualification, so a host lower
        // bound can never widen the domain below zero; the bound only narrows it.
        if ($value < 0) {
            throw new InvalidArgumentException(
                'A position ordinal cannot be negative, got ' . $value . '.'
            );
        }

        if ($value < $lowerBound) {
            throw new InvalidArgumentException(
                'Position ordinal ' . $value . ' is below the lower bound ' . $lowerBound . '.'
            );
        }

        if ($value > $upperBound) {
            throw new InvalidArgumentException(
                'Position ordinal ' . $value . ' is above the bound ' . $upperBound . '.'
            );
        }

        return new self($value);
    }

    public function ordinal(): int
    {
        return $this->ordinal;
    }

    public function equals(self $other): bool
    {
        return $this->ordinal === $other->ordinal;
    }

    private static function asOrdinal(mixed $ordinal): int
    {
        if (is_int($ordinal)) {
            return $ordinal;
        }

        // The host may hold the ordinal as the literal it read; a float, an empty
        // string or any non-digit text is not an ordinal and must not be coerced.
        if (is_string($ordinal) && ctype_digit($ordinal)) {
            return (int) $ordinal;
        }

        throw new InvalidArgumentException(
            'A position ordinal must be an integer or an integer literal, got ' . get_debug_type($ordinal) . '.'
        );
    }
}
