<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Enums\AiSocialExchangeType;

/**
 * A known social exchange recognised in an inbound message.
 *
 * Producing no instance is the normal outcome, not a failure: the module answers a message
 * only when it places it as an exchange it has an authored response for. That is what keeps
 * the default answer to ambiguous or sarcastic text silent instead of a guess.
 */
readonly class InboundSocialExchange
{
    /**
     * @param array<string, mixed> $terms
     */
    public function __construct(
        public AiSocialExchangeType $type,
        public array $terms,
    ) {
    }
}
