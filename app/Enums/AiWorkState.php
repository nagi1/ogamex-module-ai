<?php

namespace Modules\AI\Enums;

enum AiWorkState: int
{
    case Pending = 1;
    case Leased = 2;
    case Retry = 3;
    case Completed = 4;
    case Failed = 5;
}
