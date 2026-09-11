<?php

namespace Modules\AI\Infrastructure\Language;

use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\LanguageRequest;
use Modules\AI\Domain\Conversation\LanguageResult;
use Modules\AI\Enums\AiLanguageResultStatus;

class NullLanguageGateway implements LanguageGateway
{
    public function generateConversationReply(LanguageRequest $request): LanguageResult
    {
        return app()->makeWith(LanguageResult::class, [
            'status' => AiLanguageResultStatus::Disabled,
            'text' => null,
            'interpretation' => null,
            'proposals' => [],
            'inputTokens' => 0,
            'outputTokens' => 0,
            'providerRequestId' => null,
            'provider' => null,
            'model' => null,
        ]);
    }
}
