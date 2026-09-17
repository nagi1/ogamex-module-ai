<?php

namespace Modules\AI\Enums;

/**
 * Intent categories are module-owned. The host remains the authority on
 * whether an intent is legal and how it changes game state.
 */
enum AiCandidateActionType: int
{
    case DoNothing = 1;
    case Build = 3;
    case Research = 4;
    case QueueUnits = 5;
    case FleetSave = 6;
    case Spy = 7;
    case Raid = 8;
    case Colonize = 9;
    case Recall = 10;
    case Expedition = 11;
    case Transfer = 12;
}
