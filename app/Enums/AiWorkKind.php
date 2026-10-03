<?php

namespace Modules\AI\Enums;

enum AiWorkKind: int
{
    case BuildFirstBuilding = 1;
    case RunSession = 2;
    case QueueResearch = 3;
    case QueueUnits = 4;
    case Colonize = 5;
    case FleetSave = 6;
    case Spy = 7;
    case Raid = 8;
    case Recall = 9;
    case Expedition = 10;
    case Transfer = 11;
    case Recycle = 12;
    case SetMinePercent = 13;
    case Phalanx = 14;
    case Defend = 15;
    case Trade = 16;
    case Relocate = 17;

    public function actionType(): AiActionType
    {
        return match ($this) {
            self::QueueResearch => AiActionType::QueueResearch,
            self::QueueUnits => AiActionType::QueueUnits,
            self::Colonize => AiActionType::CreateColony,
            self::FleetSave, self::Spy, self::Raid, self::Expedition, self::Transfer, self::Recycle, self::Defend => AiActionType::DispatchFleet,
            self::Recall => AiActionType::RecallFleet,
            self::SetMinePercent => AiActionType::SetMinePercent,
            self::Phalanx => AiActionType::PhalanxScan,
            self::Trade => AiActionType::TradeResources,
            self::Relocate => AiActionType::RelocatePlanet,
            self::BuildFirstBuilding, self::RunSession => AiActionType::QueueBuilding,
        };
    }
}
