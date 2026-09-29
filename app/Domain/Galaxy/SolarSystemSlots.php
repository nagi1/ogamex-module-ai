<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Galaxy;

/**
 * Slot topology of a solar system: 15 colonizable planet slots plus the one
 * uncolonizable Outer Space slot, 16 total. Slots are numbered from 1, so any
 * planner that picks a target must stay inside 1..15 and never return 16.
 */
final class SolarSystemSlots
{
    public const FIRST_COLONIZABLE_SLOT = 1;

    public const LAST_COLONIZABLE_SLOT = 15;

    public const TOTAL_SLOTS = 16;

    private function __construct()
    {
    }

    public static function isColonizable(int $slot): bool
    {
        return $slot >= self::FIRST_COLONIZABLE_SLOT
            && $slot <= self::LAST_COLONIZABLE_SLOT;
    }

    /**
     * A first-fit scan over candidate slots, bounded by the same rule planners
     * must apply: candidates outside 1..15 are skipped instead of returned.
     *
     * @param  iterable<int>  $candidateSlots
     */
    public static function firstColonizableSlot(iterable $candidateSlots): ?int
    {
        foreach ($candidateSlots as $slot) {
            if (self::isColonizable($slot)) {
                return $slot;
            }
        }

        return null;
    }
}
