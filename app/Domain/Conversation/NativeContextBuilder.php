<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Contracts\ContextBuilder;

class NativeContextBuilder implements ContextBuilder
{
    public function buildConversationContext(array $sections, int $maximumCharacters): ConversationContext
    {
        $selected = [];
        foreach ($this->prioritizedSections($sections) as $section) {
            $candidate = json_encode([$section->name => $section->value], JSON_THROW_ON_ERROR);
            if (mb_strlen(json_encode($selected, JSON_THROW_ON_ERROR)) + mb_strlen($candidate) > max(0, $maximumCharacters)) {
                if ($section->isProtected) {
                    return new ConversationContext($selected, json_encode($selected, JSON_THROW_ON_ERROR), false);
                }

                continue;
            }
            $selected[$section->name] = $section->value;
        }

        return new ConversationContext($selected, json_encode($selected, JSON_THROW_ON_ERROR), true);
    }

    /** @param array<array-key, mixed|ConversationContextSection> $sections
     * @return list<ConversationContextSection>
     */
    private function prioritizedSections(array $sections): array
    {
        $normalizedSections = [];

        foreach ($sections as $name => $value) {
            $normalizedSections[] = $value instanceof ConversationContextSection
                ? $value
                : new ConversationContextSection((string) $name, $value);
        }

        usort($normalizedSections, fn (ConversationContextSection $left, ConversationContextSection $right): int => $right->isProtected <=> $left->isProtected);

        return $normalizedSections;
    }
}
