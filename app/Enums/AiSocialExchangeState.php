<?php

namespace Modules\AI\Enums;

enum AiSocialExchangeState: int
{
    case Proposed = 1;
    case Responded = 2;
    case Expired = 3;
}
