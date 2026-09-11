<?php

namespace Modules\AI\Support;

use Modules\AI\Enums\AiQueueActionReason;

/** Transport-neutral outcome recorded in the module's action receipt. */
readonly class AiActionResult
{
    public function __construct(
        public bool $successful,
        public string $reason,
        public int|null $queueId = null,
    ) {
    }

    public static function queued(int $queueId): self
    {
        return app()->makeWith(self::class, [
            'successful' => true,
            'reason' => AiQueueActionReason::Queued->value,
            'queueId' => $queueId,
        ]);
    }

    public static function rejected(AiQueueActionReason|string $reason): self
    {
        return app()->makeWith(self::class, [
            'successful' => false,
            'reason' => $reason instanceof AiQueueActionReason ? $reason->value : $reason,
        ]);
    }
}
