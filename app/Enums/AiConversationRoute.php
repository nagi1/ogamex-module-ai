<?php

namespace Modules\AI\Enums;

/**
 * How a reply the module has already sealed reaches the recipient.
 *
 * The plan's escalation ladder names five routes. Two of them are implemented: silence has
 * no sealed reply to route, and template and authored dialogue are the same route here
 * because the module's authored variants always carry a known exchange. Interpretation of
 * unrestricted free-form text is not implemented, so an unrecognised message is never
 * escalated and is answered with nothing.
 */
enum AiConversationRoute: string
{
    /** Wording the module authored, sent without involving a provider. */
    case Authored = 'authored';

    /** At most one bounded foreground request turns settled intent into varied wording. */
    case Realization = 'realization';
}
