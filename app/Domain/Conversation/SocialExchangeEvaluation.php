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
        // CiF evidence, present only when the external social driver actually answered.
        // The native stance stays on `response`/`reason`; these carry the driver's volition
        // magnitude and protocol step for a hybrid consumer.
        public float|null $volition = null,
        public string|null $step = null,
    ) {
    }
}
