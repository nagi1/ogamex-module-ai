<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;

readonly class SocialExchangeEvaluation
{
    /**
     * @param array<string, mixed>|null $counterTerms
     */
    public function __construct(
        public AiSocialResponse $response,
        public AiSocialResponseReason $reason,
        public array|null $counterTerms = null,
    ) {
    }
}
