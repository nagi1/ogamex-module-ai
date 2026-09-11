<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Enums\AiSocialResponse;

readonly class SocialExchangeEvaluation
{
    /**
     * @param array<string, mixed>|null $counterTerms
     */
    public function __construct(
        public AiSocialResponse $response,
        public string $reason,
        public array|null $counterTerms = null,
    ) {
    }
}
