<?php

namespace Modules\AI\Tests\Support;

use Closure;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Domain\Conversation\ConversationContext;
use Modules\AI\Domain\Conversation\NativeContextBuilder;

final class ConcurrentLanguageRequestContextBuilder implements ContextBuilder
{
    /** @param callable(): void $duringBuild */
    public function __construct(private readonly Closure $duringBuild)
    {
    }

    public function buildConversationContext(array $sections, int $maximumCharacters): ConversationContext
    {
        ($this->duringBuild)();

        return app(NativeContextBuilder::class)->buildConversationContext($sections, $maximumCharacters);
    }
}
