<?php

namespace Modules\AI\Enums;

enum AiCommitmentState: int
{
    case Proposed = 1;
    case Accepted = 2;
    case Fulfilled = 3;
    case Broken = 4;
    case Expired = 5;
    case Cancelled = 6;
}
