<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use InvalidArgumentException;

/**
 * Display-only planet name. Identity stays with the game object's own id/coordinates, so this
 * value is never a lookup key: it only carries the player's text, case and characters verbatim.
 */
final readonly class PlanetLabel
{
    /**
     * The constructor is private so a blank name can never reach display code by bypassing
     * fromRaw(), which is the single place the label is normalised.
     */
    private function __construct(
        public string $value,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the raw name has no non-whitespace characters left.
     */
    public static function fromRaw(string $raw): self
    {
        $value = trim($raw);

        if ($value === '') {
            throw new InvalidArgumentException('A planet label must contain at least one non-whitespace character.');
        }

        return new self($value);
    }
}
