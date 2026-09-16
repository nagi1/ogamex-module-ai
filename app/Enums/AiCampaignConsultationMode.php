<?php

namespace Modules\AI\Enums;

/**
 * The three states of the campaign consultation lane.
 *
 * `Off` never resolves the SDK configuration or contacts a provider; `Observe` records a
 * validated recommendation without applying it; `Advice` may apply a profile-bounded
 * ranking adjustment. The admission action resolves one of these before any provider work.
 */
enum AiCampaignConsultationMode: string
{
    case Off = 'off';
    case Observe = 'observe';
    case Advice = 'advice';
}
