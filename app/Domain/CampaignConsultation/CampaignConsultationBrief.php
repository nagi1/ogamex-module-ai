<?php

namespace Modules\AI\Domain\CampaignConsultation;

/**
 * The redacted, already-serialized brief one consultation is answered from.
 *
 * The gateway never sees an Eloquent model or a live service, only the serialized text and the
 * ids the agent may name. `candidateIds` are the legal candidates the recommendation must choose
 * among; `evidenceIds` are the evidence items the brief carried, which the agent may cite and
 * the validator bounds the recommendation to.
 */
readonly class CampaignConsultationBrief
{
    /**
     * @param list<int> $candidateIds
     * @param list<int> $evidenceIds
     */
    public function __construct(
        public string $serialized,
        public array $candidateIds,
        public array $evidenceIds,
    ) {
    }
}
