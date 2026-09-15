<?php

namespace Modules\AI\Domain\Decision;

/**
 * A parked save this account can bring home: its own in-flight deployment.
 *
 * The host never returns a deployment on its own, so the recall is the other
 * half of the save. The deployment is this account's and between two different
 * own bodies; the planet is the deployment's origin, kept for the session
 * context the dispatch advances.
 */
readonly class QueueableRecall
{
    public function __construct(
        public int $planetId,
        public int $missionId,
    ) {
    }
}
