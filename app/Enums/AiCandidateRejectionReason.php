<?php

namespace Modules\AI\Enums;

/** Stable reasons for omitting an otherwise visible candidate. */
enum AiCandidateRejectionReason: string
{
    case AttackNotPermitted = 'attack_not_permitted';
    case StaleTargetIntel = 'stale_target_intel';
    case ScoreBelowViability = 'score_below_viability';
    case RaidNotViable = 'raid_not_viable';
    case DefendedTargetPreferred = 'defended_target_preferred';
}
