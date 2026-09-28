<?php

namespace Modules\AI\Enums;

/**
 * What the account does with accumulated resources: whether it spends
 * immediately, on a schedule, saves toward a named goal, or hoards by
 * carelessness. This is the missing concept behind the accidental 2B-metal
 * stockpiles.
 */
enum AiStockpileStrategy: int
{
    case ImmediateSpender = 1;
    case ScheduledSpender = 2;
    case GoalSaver = 3;
    case FleetSaveBanker = 4;
    case BunkerBanker = 5;
    case CarelessHoarder = 6;
}
