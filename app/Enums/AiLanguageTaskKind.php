<?php

namespace Modules\AI\Enums;

/**
 * The kinds of language work the module asks a provider to do.
 *
 * A task kind is a routing key, not a second interpretation pass: one request stays one request,
 * and the kind only decides which ladder answers it.
 */
enum AiLanguageTaskKind: string
{
    /** A substantive reply a human will read, where wording quality is the point. */
    case ConversationReply = 'conversation_reply';

    /** A critical campaign decision consultation, where a typed recommendation over supplied candidates is the point. */
    case CampaignConsultation = 'campaign_consultation';

    /** The opt-in sanitized provider run, which must be able to name its own vendor. */
    case Conformance = 'conformance';
}
