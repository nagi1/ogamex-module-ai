<?php

namespace Modules\AI\Enums;

enum AiSocialResponse: int
{
    case Accept = 1;
    case Reject = 2;
    case Counter = 3;
    case Clarify = 4;
}
