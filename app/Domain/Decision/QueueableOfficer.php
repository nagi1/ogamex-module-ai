<?php

namespace Modules\AI\Domain\Decision;

/** An officer the host sells, for how long, and the account's first planet the receipt is filed under. */
readonly class QueueableOfficer
{
    public function __construct(
        public string $officerKey,
        public int $days,
        public int $planetId,
    ) {
    }
}
