<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Models\AiCampaignConsultationSignal;

/**
 * Records that a material campaign event happened, once per open signal.
 *
 * The same event can fire again after the earlier signal was consumed, which is what lets a
 * contested stronghold re-trigger after the first consultation resolved. The guard is "no open
 * signal for this campaign and trigger", not "no row ever".
 */
class RecordCampaignConsultationSignalAction
{
    public function handle(int $campaignId, AiCampaignConsultationTrigger $trigger, CarbonImmutable $at): void
    {
        // ponytail: no unique constraint backs the open-signal guard, so two racing writers can
        // leave two rows; the admission cooldown dedupes the provider call anyway, and the
        // single-threaded campaign scheduler makes the race theoretical. A partial unique index
        // on (campaign_id, trigger) WHERE consumed_at IS NULL is the upgrade if it ever matters.
        $open = AiCampaignConsultationSignal::query()
            ->where('campaign_id', $campaignId)
            ->where('trigger', $trigger->value)
            ->whereNull('consumed_at')
            ->exists();

        if ($open) {
            return;
        }

        AiCampaignConsultationSignal::query()->create([
            'campaign_id' => $campaignId,
            'trigger' => $trigger->value,
            'fired_at' => $at,
            'consumed_at' => null,
        ]);
    }
}
