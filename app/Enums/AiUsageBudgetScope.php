<?php

namespace Modules\AI\Enums;

enum AiUsageBudgetScope: int
{
    case Universe = 1;
    case Player = 2;
    case Conversation = 3;
}
