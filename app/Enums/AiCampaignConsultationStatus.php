<?php

namespace Modules\AI\Enums;

/**
 * The outcome of one campaign consultation, mirroring the language result statuses.
 *
 * Only `Completed` carries a recommendation; every other state preserves the native campaign
 * decision, which is the fail-closed property the lane must hold under provider trouble.
 */
enum AiCampaignConsultationStatus: string
{
    case Completed = 'completed';
    case Disabled = 'disabled';
    case Invalid = 'invalid';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
}
