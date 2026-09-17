<?php

namespace Modules\AI\Enums;

/** Stable explanation labels written to a decision trace. */
enum AiCandidateReason: string
{
    case AlwaysAvailable = 'always_available';
    case EligibleFleetSave = 'eligible_fleetsave';
    case ProactiveSave = 'proactive_save';
    case EligibleRecall = 'eligible_recall';
    case EligibleExpedition = 'eligible_expedition';
    case EligibleTransfer = 'eligible_transfer';
    case EligibleRecycle = 'eligible_recycle';
    case FreshVisibleReport = 'fresh_visible_report';

    public static function publishedCapability(AiCapability $capability): string
    {
        return 'published_capability:' . $capability->value;
    }

    public static function reportSource(int $reportId): string
    {
        return 'espionage_report:' . $reportId;
    }
}
