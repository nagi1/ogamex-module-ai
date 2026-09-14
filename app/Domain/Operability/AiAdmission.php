<?php

namespace Modules\AI\Domain\Operability;

use Modules\AI\Enums\AiStopReason;

/**
 * The outcome of one admission check.
 *
 * `limit` is what the caller may do, which is not always what it asked for: a pass that
 * reached the batch size is still allowed, but only for the part it may take. The reason
 * travels with the admission so the caller records a throttle instead of quietly
 * under-working, which would look like an idle population in the pilot report.
 *
 * @property array<string, mixed> $context
 */
readonly class AiAdmission
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $allowed = false,
        public int $limit = 0,
        public AiStopReason|null $stopReason = null,
        public array $context = [],
    ) {
    }
}
