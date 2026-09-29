<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Galaxy;

/**
 * One row of a galaxy view, carried exactly as the host reported it.
 *
 * WIK-206: the field semantics come from a community-sourced wiki that the host has not confirmed,
 * so this record stays inert. It is a read-only observation, never an input to a decision, and it
 * deliberately exposes no derived fleet, resource or combat value — attaching one here would turn a
 * raw scan into a de-facto doctrine without the mechanics ever being confirmed.
 */
final readonly class GalaxyScanObservation
{
    public function __construct(
        private int $galaxy,
        private int $system,
        private int $position,
        private ?string $occupant,
        private bool $hasDebris,
    ) {
    }

    public function galaxy(): int
    {
        return $this->galaxy;
    }

    public function system(): int
    {
        return $this->system;
    }

    public function position(): int
    {
        return $this->position;
    }

    /**
     * Unparsed as reported: whatever the galaxy view showed for this slot, null when it showed none.
     */
    public function occupant(): ?string
    {
        return $this->occupant;
    }

    public function hasDebris(): bool
    {
        return $this->hasDebris;
    }

    /**
     * Always false: no field in this row has been confirmed by the host yet, and hard-coding the
     * answer keeps a caller from declaring the observation trustworthy on its own.
     */
    public function isVerified(): bool
    {
        return false;
    }
}
