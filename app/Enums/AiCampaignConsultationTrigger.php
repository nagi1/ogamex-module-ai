<?php

namespace Modules\AI\Enums;

/**
 * A campaign event material enough to consult on. The admission layer only accepts the
 * triggers the operator kept in the allowlist; the lane can never invent a new one.
 */
enum AiCampaignConsultationTrigger: string
{
    case FleetLoss = 'fleet_loss';
    case RepeatedSetback = 'repeated_setback';
    case ContestedObjective = 'contested_objective';
    case CoalitionConflict = 'coalition_conflict';
    case NewPhase = 'new_phase';
    case RankChange = 'rank_change';
}
