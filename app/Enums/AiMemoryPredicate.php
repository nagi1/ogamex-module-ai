<?php

namespace Modules\AI\Enums;

enum AiMemoryPredicate: int
{
    case AllianceMembership = 1;
    case ResourceDebt = 2;
    case AttackReceived = 3;
    case TruceAgreed = 4;
    case TruceBroken = 5;
}
