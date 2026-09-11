<?php

namespace Modules\AI\Enums;

/** Defined outcomes from the module-owned queue adapter. */
enum AiQueueActionReason: string
{
    case Queued = 'queued';
    case NoOwnedPlanet = 'no_owned_planet';
    case PlanetNotOwned = 'planet_not_owned';
    case PlayerBanned = 'player_banned';
    case VacationMode = 'vacation_mode';
    case NotABuilding = 'not_a_building';
    case ShipyardBusy = 'shipyard_busy';
    case QueueNotCreated = 'queue_not_created';
}
