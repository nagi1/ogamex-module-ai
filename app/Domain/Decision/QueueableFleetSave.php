<?php

namespace Modules\AI\Domain\Decision;

/**
 * A fleet this account can move off a threatened planet to another of its own.
 *
 * The destination is another planet the account already owns, so the save needs
 * no hostile target and no moon: a deployment between own planets is the classic
 * safe save, and it is what the second planet a colony provides finally enables.
 */
readonly class QueueableFleetSave
{
    public function __construct(
        public int $originPlanetId,
        public int $destinationPlanetId,
        public int $missionType,
    ) {
    }
}
