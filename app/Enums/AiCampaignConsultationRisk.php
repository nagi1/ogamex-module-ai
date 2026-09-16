<?php

namespace Modules\AI\Enums;

/**
 * The bounded risk a consultation recommendation may attach to its choice.
 */
enum AiCampaignConsultationRisk: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
}
