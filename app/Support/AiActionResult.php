<?php

namespace Modules\AI\Support;

use Modules\AI\Enums\AiQueueActionReason;

/** Transport-neutral outcome recorded in the module's action receipt. */
readonly class AiActionResult
{
    private function __construct(
        public bool $successful,
        public string $reason,
        public int|null $queueId = null,
    ) {
    }

    public static function queued(int $queueId): self
    {
        return new self(true, AiQueueActionReason::Queued->value, $queueId);
    }

    public static function rejected(AiQueueActionReason|string $reason): self
    {
        return new self(false, $reason instanceof AiQueueActionReason ? $reason->value : $reason);
    }
}
