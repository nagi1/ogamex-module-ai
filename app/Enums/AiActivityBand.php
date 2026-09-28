<?php

namespace Modules\AI\Enums;

/**
 * How much the account is around: the lifestyle constraint the routine's presence
 * band reads, independent of what the account does when it is online. A casual
 * fleeter and a hardcore miner are both possible.
 */
enum AiActivityBand: int
{
    case Casual = 1;
    case Regular = 2;
    case Active = 3;
    case Hardcore = 4;
}
