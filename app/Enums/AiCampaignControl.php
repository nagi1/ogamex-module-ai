<?php

namespace Modules\AI\Enums;

/**
 * The fixed set of campaign controls the console offers. Each maps to an action or command that
 * already exists, and the enum is the allow-list that keeps an unknown control out of the job.
 * The value doubles as the audited operation name in the operation log.
 */
enum AiCampaignControl: string
{
    case Open = 'campaign:open';
    case Declare = 'campaign:declare';
    case Advance = 'campaign:advance';
    case ApplyAlliances = 'campaign:apply-alliances';
    case BondAlliances = 'campaign:bond-alliances';
}
