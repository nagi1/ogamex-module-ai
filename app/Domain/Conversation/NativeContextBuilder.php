<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Contracts\ContextBuilder;

class NativeContextBuilder implements ContextBuilder
{
    public function buildConversationContext(array $sections, int $maximumCharacters): ConversationContext
    {
        $selected = [];
        foreach ($sections as $name => $value) {
            $candidate = json_encode([$name => $value], JSON_THROW_ON_ERROR);
            if (mb_strlen(json_encode($selected, JSON_THROW_ON_ERROR)) + mb_strlen($candidate) > max(0, $maximumCharacters)) {
                continue;
            }
            $selected[$name] = $value;
        }
        return new ConversationContext($selected, json_encode($selected, JSON_THROW_ON_ERROR));
    }
}
