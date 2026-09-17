<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Enums\AiConversationRoute;
use Modules\AI\Enums\AiSocialExchangeType;

/**
 * Chooses the route a sealed reply takes before any model capacity is reserved.
 *
 * A greeting or a thank-you is the routine case the plan keeps on authored text: there is
 * nothing in it that varied wording improves, the module already has several variants, and
 * "do not automatically escalate substantive conversation" starts with not escalating
 * trivial conversation. A substantive exchange is where the wording carries the stance
 * cognition already decided, so it may be realized by a provider.
 *
 * Whether a realization is actually admissible is not decided here: the reply action still
 * refuses a counterparty that is itself an AI, a missing persona, protected-context
 * overflow and exhausted capacity, and delivers the authored text instead.
 */
class ConversationRoutePolicy
{
    public function routeFor(AiSocialExchangeType $type): AiConversationRoute
    {
        return match ($type) {
            AiSocialExchangeType::Greeting, AiSocialExchangeType::Thanks, AiSocialExchangeType::AttackerNotice => AiConversationRoute::Authored,
            default => AiConversationRoute::Realization,
        };
    }
}
