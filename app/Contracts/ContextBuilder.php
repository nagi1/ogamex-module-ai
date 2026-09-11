<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Conversation\ConversationContext;

interface ContextBuilder
{
    /** @param array<string, mixed> $sections */
    public function buildConversationContext(array $sections, int $maximumCharacters): ConversationContext;
}
