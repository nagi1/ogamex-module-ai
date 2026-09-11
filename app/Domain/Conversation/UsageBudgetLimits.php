<?php

namespace Modules\AI\Domain\Conversation;

readonly class UsageBudgetLimits
{
    public function __construct(public UsageBudgetLimit $universe, public UsageBudgetLimit $player, public UsageBudgetLimit $conversation)
    {
    }
}
