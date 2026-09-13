<?php

namespace Modules\AI\Enums;

/** Sources whose committed rows may become module-owned AI observations. */
enum AiObservationSource: int
{
    case ChatMessage = 1;
    case AllianceMembershipJoined = 2;
    case AllianceMembershipLeft = 3;
    case BuildingQueue = 4;
    case BattleReport = 5;
}
