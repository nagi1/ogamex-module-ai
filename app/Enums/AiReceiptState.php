<?php

namespace Modules\AI\Enums;

enum AiReceiptState: int
{
    case Processing = 1;
    case Completed = 2;
    case Rejected = 3;
}
