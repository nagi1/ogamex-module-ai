<?php

namespace Modules\AI\Domain\Decision;

/**
 * A fleet this account can move off a threatened planet.
 *
 * The ordinary save is a deployment to another planet the account owns. When it
 * owns no other body (and no moon), the fallback is a harvest-save: the whole
 * fleet rides a recycle mission to a host debris field, so it is in the air with
 * no second planet required. A non-zero harvest coordinate marks that fallback
 * (destinationPlanetId is then 0).
 */
readonly class QueueableFleetSave
{
    public function __construct(
        public int $originPlanetId,
        public int $destinationPlanetId,
        public int $missionType,
        public int $shadowDestinationPlanetId = 0,
        public int $harvestGalaxy = 0,
        public int $harvestSystem = 0,
        public int $harvestPosition = 0,
        public float $speed = 1.0,
    ) {
    }
}
