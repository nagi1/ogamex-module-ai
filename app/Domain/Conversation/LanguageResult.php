<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Enums\AiLanguageInterpretation;
use Modules\AI\Enums\AiLanguageResultStatus;

readonly class LanguageResult
{
    /**
     * @param list<LanguageProposal> $proposals
     * @param int $cachedInputTokens the part of the input the provider served from its own cache;
     *        the SDK already excludes it from `inputTokens`, so it travels separately to be priced
     */
    public function __construct(
        public AiLanguageResultStatus $status,
        public string|null $text,
        public AiLanguageInterpretation|null $interpretation,
        public array $proposals,
        public int $inputTokens,
        public int $outputTokens,
        public string|null $providerRequestId,
        public string|null $provider,
        public string|null $model,
        public int $cachedInputTokens = 0,
    ) {
    }
}
