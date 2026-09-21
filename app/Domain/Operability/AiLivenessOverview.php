<?php

namespace Modules\AI\Domain\Operability;

/**
 * Whether the population is quiet because the game is quiet, or because it is broken.
 *
 * @property string|null $lastActivityAt
 * @property int $overdueAccounts
 * @property int $dueWork
 * @property int $sessionsInFlight
 */
readonly class AiLivenessOverview
{
    public function __construct(
        public string|null $lastActivityAt = null,
        public int $overdueAccounts = 0,
        public int $dueWork = 0,
        public int $sessionsInFlight = 0,
    ) {
    }
}
