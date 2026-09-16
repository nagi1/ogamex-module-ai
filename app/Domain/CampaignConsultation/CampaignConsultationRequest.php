<?php

namespace Modules\AI\Domain\CampaignConsultation;

use Modules\AI\Domain\Language\AiProviderLadder;
use Modules\AI\Enums\AiCampaignConsultationTrigger;

/**
 * One bounded consultation request.
 *
 * The brief is already built and serialized by the caller, so the gateway never receives an
 * Eloquent model or a live service — only the redacted text and the candidate ids the agent
 * may choose among. The ladder is the SDK's own ordered provider list.
 */
readonly class CampaignConsultationRequest
{
    /**
     * @param list<int> $candidateIds
     */
    public function __construct(
        public AiCampaignConsultationTrigger $trigger,
        public string $serializedBrief,
        public array $candidateIds,
        public AiProviderLadder $ladder,
        public int $timeoutSeconds,
        public int $maximumReasonCharacters,
        public int $maximumEvidenceIds,
    ) {
    }
}
