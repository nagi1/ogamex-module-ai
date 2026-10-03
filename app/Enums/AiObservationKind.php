<?php

namespace Modules\AI\Enums;

/** The durable meaning an observation has for the receiving AI player. */
enum AiObservationKind: int
{
    case DirectChatMessageReceived = 1;
    case AllianceMembershipJoined = 2;
    case AllianceMembershipLeft = 3;
    case BuildingCompleted = 4;
    case BattleReportObserved = 5;
    case AllianceChatMessageReceived = 6;
    case AllyUnderAttack = 7;
    case TransferReceived = 8;
    case ExpeditionResult = 9;
    case ExpeditionFleetLost = 10;
}
