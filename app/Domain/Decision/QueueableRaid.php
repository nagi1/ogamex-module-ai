<?php

namespace Modules\AI\Domain\Decision;

/**
 * A raid this account can legally launch now: a fleet from an owned planet at a
 * target it has fresh intel on, whose sampled net profit clears the profit test.
 */
readonly class QueueableRaid
{
    public function __construct(
        public int $originPlanetId,
        public int $targetGalaxy,
        public int $targetSystem,
        public int $targetPosition,
        public int $targetType,
        public int $missionType,
        /** @var array<string, int> the counter-selected launch subset; empty means the whole fleet. */
        public array $launchUnits = [],
    ) {
    }
}
