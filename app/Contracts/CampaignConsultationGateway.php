<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRecommendation;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRequest;

/**
 * The transport seam for campaign consultation. A disabled implementation returns a typed
 * disabled result without loading SDK configuration or contacting a provider.
 */
interface CampaignConsultationGateway
{
    public function recommend(CampaignConsultationRequest $request): CampaignConsultationRecommendation;
}
