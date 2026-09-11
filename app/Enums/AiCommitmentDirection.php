<?php

namespace Modules\AI\Enums;

enum AiCommitmentDirection: int
{
    case PromisedByPlayer = 1;
    case ExpectedFromCounterparty = 2;
}
