<?php

namespace Modules\AI\Domain\Decision;

/**
 * A raid target the account can scan from an owned moon that has a sensor phalanx.
 *
 * The moon is the origin (it carries the phalanx); the target is the planet the
 * raid would fly to. The host's own legality gate decides whether the scan is in
 * range, never the module.
 */
readonly class QueueablePhalanx
{
    public function __construct(
        public int $moonPlanetId,
        public int $targetPlanetId,
    ) {
    }
}
