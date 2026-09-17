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
    case NotAResearch = 'not_a_research';
    case NotAUnit = 'not_a_unit';
    case ShipyardBusy = 'shipyard_busy';
    case QueueNotCreated = 'queue_not_created';
    case NothingQueueable = 'nothing_queueable';
    case TargetActiveAtDispatch = 'target_active_at_dispatch';
    case TargetStagingAtDispatch = 'target_staging_at_dispatch';
    case NoDeploymentToRecall = 'no_deployment_to_recall';
    case UnderAttack = 'under_attack';
    case NoDisposableFleet = 'no_disposable_fleet';
    case NoTransportFleet = 'no_transport_fleet';
    case SourceShortAtDispatch = 'source_short_at_dispatch';
    case PercentApplied = 'percent_applied';
    case PercentRefused = 'percent_refused';
}
