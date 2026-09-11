<?php

namespace Modules\AI\Domain\Conversation;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiSocialExchangeType;

readonly class SocialExchangeContext
{
    /**
     * @param array<string, mixed> $terms
     */
    public function __construct(
        public int $exchangeId,
        public AiSocialExchangeType $type,
        public array $terms,
        public float $trust,
        public float $affinity,
        public float $threat,
        public int $outstandingCommitments,
        public float $availableAmount,
        public CarbonImmutable $evaluatedAt,
    ) {
    }
}
