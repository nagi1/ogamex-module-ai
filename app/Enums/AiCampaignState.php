<?php

namespace Modules\AI\Enums;

enum AiCampaignState: int
{
    case Preparing = 1;
    case Active = 2;
    case Resolved = 3;
    case Failed = 4;
}
