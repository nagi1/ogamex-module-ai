<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Coordinates;

use InvalidArgumentException;

/**
 * A galaxy:system:position coordinate.
 *
 * The wiki documents the shape only and states no numbers, so no bound is encoded here:
 * any range added now would be invented and would have to be undone once the host confirms it.
 */
final readonly class Coordinate
{
    public function __construct(
        public int $galaxy,
        public int $system,
        public int $position,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the value is not three colon separated whole numbers.
     */
    public static function parse(string $value): self
    {
        $parts = explode(':', $value);

        if (count($parts) !== 3) {
            throw new InvalidArgumentException(sprintf('"%s" is not a galaxy:system:position coordinate.', $value));
        }

        foreach ($parts as $part) {
            if (! ctype_digit($part)) {
                throw new InvalidArgumentException(sprintf('"%s" has a part that is not a whole number.', $value));
            }
        }

        return new self((int) $parts[0], (int) $parts[1], (int) $parts[2]);
    }

    public function format(): string
    {
        return sprintf('%d:%d:%d', $this->galaxy, $this->system, $this->position);
    }
}
