<?php

namespace Modules\AI\Domain\Conversation;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiArchetype;
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
        public CarbonImmutable|null $dueAt = null,
        public float $respect = 0,
        public float $socialImportance = 0,
        // An external cognition driver needs to know whose character state answers and
        // which counterparty it addresses. Both are optional so the native engine, which
        // needs neither, stays unaffected.
        public AiArchetype|null $archetype = null,
        public int|null $counterpartyPlayerId = null,
    ) {
    }
}
