<?php

declare(strict_types=1);

namespace Modules\AI\Domain;

use Modules\AI\Exceptions\InvalidCoordinateException;

/**
 * A galaxy:system:position reference.
 *
 * The source documents the shape only and states no galaxy, system or position bounds, so this type
 * carries no numeric policy: there is no ratio, cap, cost or threshold to tune, and no caller-supplied
 * limit is invented here. A caller that has one enforces it.
 *
 * The canonical string is identical to the accepted input, which is why a part written with a leading
 * zero is refused instead of normalised.
 */
final readonly class Coordinate
{
    /** The separator that defines the canonical form, used for parsing and formatting alike. */
    private const SEPARATOR = ':';

    /**
     * One part of a coordinate: a decimal integer written without a sign and without a leading zero,
     * because a position of zero is not a coordinate the game places anything at.
     */
    private const PART_PATTERN = '~\A[1-9][0-9]*\z~';

    /** Galaxy, system and position: the coordinate has three parts, no more and no fewer. */
    private const PART_COUNT = 3;

    private function __construct(
        public int $galaxy,
        public int $system,
        public int $position,
    ) {
    }

    public static function fromString(string $value): self
    {
        $parts = self::parts($value);

        return new self($parts[0], $parts[1], $parts[2]);
    }

    public function format(): string
    {
        return implode(self::SEPARATOR, [$this->galaxy, $this->system, $this->position]);
    }

    public function equals(self $other): bool
    {
        return $this->galaxy === $other->galaxy
            && $this->system === $other->system
            && $this->position === $other->position;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function parts(string $value): array
    {
        $parts = explode(self::SEPARATOR, $value);

        if (count($parts) !== self::PART_COUNT) {
            throw InvalidCoordinateException::forValue($value);
        }

        foreach ($parts as $part) {
            if (preg_match(self::PART_PATTERN, $part) !== 1) {
                throw InvalidCoordinateException::forValue($value);
            }
        }

        return array_map(static fn (string $part): int => (int) $part, $parts);
    }
}
