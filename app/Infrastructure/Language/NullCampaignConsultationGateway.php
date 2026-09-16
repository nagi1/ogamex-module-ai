<?php

namespace Modules\AI\Infrastructure\Language;

use Modules\AI\Contracts\CampaignConsultationGateway;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRecommendation;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRequest;
use Modules\AI\Enums\AiCampaignConsultationStatus;

/**
 * The default binding: it returns a typed disabled result without loading SDK configuration or
 * contacting a provider, which is the fail-closed state the lane ships in.
 */
class NullCampaignConsultationGateway implements CampaignConsultationGateway
{
    public function recommend(CampaignConsultationRequest $request): CampaignConsultationRecommendation
    {
        return app()->makeWith(CampaignConsultationRecommendation::class, [
            'status' => AiCampaignConsultationStatus::Disabled,
            'candidateId' => null,
            'risk' => null,
            'reason' => null,
            'evidenceIds' => [],
            'inputTokens' => 0,
            'outputTokens' => 0,
            'providerRequestId' => null,
            'provider' => null,
            'model' => null,
        ]);
    }
}
