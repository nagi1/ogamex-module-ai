<?php

namespace Modules\AI\Enums;

enum AiExperienceOutcome: int
{
    case Pending = 1;
    case Succeeded = 2;
    case Failed = 3;
    case Inconclusive = 4;
}
