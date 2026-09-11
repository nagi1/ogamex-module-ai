<?php

namespace Modules\AI\Domain\Conversation;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiLanguageProposalType;
use Modules\AI\Enums\AiSocialResource;

readonly class LanguageProposal
{
    public function __construct(
        public AiLanguageProposalType $type,
        public int $sourceMessageId,
        public AiSocialResource|null $resource,
        public int|null $amount,
        public CarbonImmutable|null $dueAt,
    ) {
    }
}
