<?php

namespace Modules\AI\Enums;

enum AiUsageReservationState: int
{
    case Reserved = 1;
    case Settled = 2;
}
