<?php

namespace Modules\AI\Domain\Conversation;

readonly class ConversationContextSection
{
    public function __construct(public string $name, public mixed $value, public bool $isProtected = false)
    {
    }
}
