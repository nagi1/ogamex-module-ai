<?php

namespace Modules\AI\Domain\Conversation;

readonly class UsageBudgetLimit
{
    public function __construct(public int $attempts, public int $inputTokens, public int $outputTokens)
    {
    }
}
