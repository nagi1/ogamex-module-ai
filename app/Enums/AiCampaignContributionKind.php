<?php

namespace Modules\AI\Enums;

enum AiCampaignContributionKind: int
{
    case Scout = 1;
    case Supply = 2;
    case Defend = 3;
    case Attack = 4;
}
