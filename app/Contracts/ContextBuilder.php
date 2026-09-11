<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Conversation\ConversationContext;
use Modules\AI\Domain\Conversation\ConversationContextSection;

interface ContextBuilder
{
    /** @param array<array-key, mixed|ConversationContextSection> $sections */
    public function buildConversationContext(array $sections, int $maximumCharacters): ConversationContext;
}
