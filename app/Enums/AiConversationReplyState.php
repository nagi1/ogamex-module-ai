<?php

namespace Modules\AI\Enums;

enum AiConversationReplyState: int
{
    case Pending = 1;
    case Sealed = 2;
    case Delivered = 3;
    case Rejected = 4;
    case Expired = 5;
}
