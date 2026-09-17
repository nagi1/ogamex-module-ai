<?php

namespace Modules\AI\Domain\CampaignConsultation;

use Modules\AI\Enums\AiCampaignConsultationRisk;
use Modules\AI\Enums\AiCampaignConsultationStatus;

/**
 * The typed result of one consultation. Only a `Completed` result names a candidate; the rest
 * preserve the native campaign decision with the reason it was not overridden.
 */
readonly class CampaignConsultationRecommendation
{
    /**
     * @param list<int> $evidenceIds
     * @param int $cachedInputTokens the part of the input the provider served from its own cache;
     *        the SDK already excludes it from `inputTokens`, so it travels separately to be priced
     */
    public function __construct(
        public AiCampaignConsultationStatus $status,
        public int|null $candidateId,
        public AiCampaignConsultationRisk|null $risk,
        public string|null $reason,
        public array $evidenceIds,
        public int $inputTokens,
        public int $outputTokens,
        public string|null $providerRequestId,
        public string|null $provider,
        public string|null $model,
        public int $cachedInputTokens = 0,
    ) {
    }
}
