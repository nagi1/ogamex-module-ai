<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Raid\RaidEstimate;

/**
 * A bounded, read-only question to the host battle engine: "if I sent this fleet
 * at this planet, what would it net?"
 *
 * Implementations sample the engine many times and return a lower-tail answer;
 * they must never write to the world, which is what makes the same question
 * replayable and comparable across candidate fleets.
 */
interface RaidEstimator
{
    /**
     * @param int $originPlanetId the planet the raiding fleet leaves from
     * @param int $targetPlanetId the planet the fleet would attack
     * @param int $seed one shared seed stream, so candidates are compared through the same luck
     */
    public function estimate(int $playerId, int $originPlanetId, int $targetPlanetId, int $seed): RaidEstimate;
}
