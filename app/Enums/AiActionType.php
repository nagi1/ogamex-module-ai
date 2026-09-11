<?php

namespace Modules\AI\Enums;

enum AiActionType: int
{
    case QueueBuilding = 1;
    case QueueResearch = 2;
    case QueueUnits = 3;
    case DispatchFleet = 4;
    case RecallFleet = 5;
    case CreateColony = 6;
}
