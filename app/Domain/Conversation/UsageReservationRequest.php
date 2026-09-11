<?php

namespace Modules\AI\Domain\Conversation;

use Carbon\CarbonImmutable;

readonly class UsageReservationRequest
{
    public function __construct(
        public string $universeScope,
        public int $playerId,
        public string $conversationKey,
        public string $requestKey,
        public int $inputTokens,
        public int $outputTokens,
        public CarbonImmutable $reservedAt,
    ) {
    }
}
