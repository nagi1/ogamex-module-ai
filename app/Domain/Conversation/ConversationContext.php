<?php

namespace Modules\AI\Domain\Conversation;

readonly class ConversationContext
{
    /** @param array<string, mixed> $sections */
    public function __construct(public array $sections, public string $serialized, public bool $protectedContentFits)
    {
    }
}
