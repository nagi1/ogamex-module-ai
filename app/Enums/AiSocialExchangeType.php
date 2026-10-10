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
    case TransportThanks = 11;

    /**
     * Whether a cognition driver's willingness may hold the exchange back. A ceasefire is asked for by someone the account
     * likes least, so a driver that gates on rapport refuses it every time (9494: 19 of 19 accepted truces withheld, none ever
     * agreed); the module's own rule on threat and terms decides it.
     */
    public function drivenByWillingness(): bool
    {
        return $this !== self::CeasefireRequest;
    }
}
