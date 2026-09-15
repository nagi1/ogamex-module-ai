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

    public function actionType(): AiActionType
    {
        return match ($this) {
            self::QueueResearch => AiActionType::QueueResearch,
            self::QueueUnits => AiActionType::QueueUnits,
            self::Colonize => AiActionType::CreateColony,
            self::FleetSave, self::Spy, self::Raid => AiActionType::DispatchFleet,
            self::BuildFirstBuilding, self::RunSession => AiActionType::QueueBuilding,
        };
    }
}
