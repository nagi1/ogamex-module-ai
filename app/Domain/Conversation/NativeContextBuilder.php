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
                    return app()->makeWith(ConversationContext::class, [
                        'sections' => $selected,
                        'serialized' => json_encode($selected, JSON_THROW_ON_ERROR),
                        'protectedContentFits' => false,
                    ]);
                }

                continue;
            }
            $selected[$section->name] = $section->value;
        }

        return app()->makeWith(ConversationContext::class, [
            'sections' => $selected,
            'serialized' => json_encode($selected, JSON_THROW_ON_ERROR),
            'protectedContentFits' => true,
        ]);
    }

    /** @param array<array-key, mixed|ConversationContextSection> $sections
     * @return list<ConversationContextSection>
     */
    private function prioritizedSections(array $sections): array
    {
        $normalizedSections = [];

        foreach ($sections as $name => $value) {
            if ($value instanceof ConversationContextSection) {
                $normalizedSections[] = $value;

                continue;
            }

            $normalizedSections[] = app()->makeWith(ConversationContextSection::class, [
                'name' => (string) $name,
                'value' => $value,
            ]);
        }

        usort($normalizedSections, fn (ConversationContextSection $left, ConversationContextSection $right): int => $right->isProtected <=> $left->isProtected);

        return $normalizedSections;
    }
}
