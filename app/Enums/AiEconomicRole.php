<?php

namespace Modules\AI\Enums;

/**
 * How the account interacts with the resource market. An identity field, not a
 * strategic input: there is no trade planner yet, so ActiveTrader names a
 * preference without pretending to have strategic significance.
 */
enum AiEconomicRole: int
{
    case SelfSufficient = 1;
    case DeutSeller = 2;
    case DeutBuyer = 3;
    case ActiveTrader = 4;
    case AllianceSupplier = 5;
}
