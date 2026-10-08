<?php

namespace Modules\AI\Enums;

/** The stance one alliance holds toward another, two of the kinds resources/behavior/diplomacy.yaml declares (alliance-politics.md). */
enum AiAllianceStance: int
{
    case War = 1;
    case Nap = 2;
}
