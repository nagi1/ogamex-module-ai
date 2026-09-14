<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Domain\Language\AiProviderLadder;

readonly class LanguageRequest
{
    /** @param list<int> $authorizedSourceMessageIds */
    public function __construct(
        public int $replyId,
        public int $playerId,
        public int $counterpartyPlayerId,
        public string $requestKey,
        public ConversationContext $context,
        public array $authorizedSourceMessageIds,
        public AiProviderLadder $ladder,
        public int $timeoutSeconds,
        public int $maximumReplyCharacters,
    ) {
    }
}
