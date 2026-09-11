<?php

namespace Modules\AI\Tests\Support;

use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\LanguageRequest;
use Modules\AI\Domain\Conversation\LanguageResult;

final class FixedLanguageGateway implements LanguageGateway
{
    /** @param (callable(LanguageRequest): void)|null $beforeResult */
    public function __construct(private readonly LanguageResult $result, private readonly mixed $beforeResult = null)
    {
    }

    public function generateConversationReply(LanguageRequest $request): LanguageResult
    {
        if ($this->beforeResult !== null) {
            ($this->beforeResult)($request);
        }

        return $this->result;
    }
}
