<?php

namespace Modules\AI\Enums;

enum AiSocialExchangeType: int
{
    case HelpRequest = 1;
    case Apology = 2;
    case Greeting = 3;
    case Thanks = 4;
    case TradeOffer = 5;
    case CeasefireRequest = 6;
    case Warning = 7;
    case CooperationRequest = 8;
    case CompensationOffer = 9;
    case AttackerNotice = 10;
}
