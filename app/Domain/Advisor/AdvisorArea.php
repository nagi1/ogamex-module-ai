<?php

namespace Modules\AI\Domain\Advisor;

/**
 * The four places the advisor may be consulted, each with its own mode (off | observe | advice).
 */
enum AdvisorArea: string
{
    /** A big defended target, multi-wave attacks, ACS with the alliance, moon destruction. */
    case Offense = 'offense';

    /** Pact offers and answers, notice, threats and tribute. */
    case Diplomacy = 'diplomacy';

    /** Replies to humans, alliance chat, post-battle comments. */
    case Conversation = 'conversation';

    /** An action whose cost or risk is a large share of the account's value. */
    case Costly = 'costly';
}
