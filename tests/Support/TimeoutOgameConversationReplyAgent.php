<?php

namespace Modules\AI\Tests\Support;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Modules\AI\Ai\Agents\OgameConversationReplyAgent;

final class TimeoutOgameConversationReplyAgent extends OgameConversationReplyAgent
{
    public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, string|null $model = null, int|null $timeout = null): AgentResponse
    {
        throw_if(true, LanguageGatewayTimeout::class, 'Provider timeout.');
    }
}
