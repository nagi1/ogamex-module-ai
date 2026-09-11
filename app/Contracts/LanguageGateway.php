<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Conversation\LanguageRequest;
use Modules\AI\Domain\Conversation\LanguageResult;

interface LanguageGateway
{
    public function generateConversationReply(LanguageRequest $request): LanguageResult;
}
