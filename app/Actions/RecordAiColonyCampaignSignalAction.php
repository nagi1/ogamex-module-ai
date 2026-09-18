<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Events\Game\PlanetCreated;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;

/**
 * Signals an active campaign when a coalition member lands a colony (P7-001).
 *
 * A colony moves the coalition's economy and stronghold positions, which is
 * exactly the kind of material change a campaign consults on. Only a new planet
 * beyond the account's first is a colony — the homeworld, a moon and a debris
 * field are not, and a non-member's colony is ordinary play.
 */
class RecordAiColonyCampaignSignalAction
{
    public function handle(PlanetCreated $event): void
    {
        // Only a planet is a colony; a moon or debris field never is.
        if ((int) $event->planetType !== PlanetType::Planet->value) {
            return;
        }

        // The account's first planet is its homeworld, not a colony. A destroyed
        // planet is gone, so it never counts as the prior body that makes this one a colony.
        if (!Planet::query()
            ->where('user_id', $event->playerId)
            ->where('id', '!=', $event->planetId)
            ->where('planet_type', PlanetType::Planet->value)
            ->where('destroyed', 0)
            ->exists()) {
            return;
        }

        // Only a coalition member (an enabled AI account) moves the campaign.
        if (!AiProfile::query()->where('player_id', $event->playerId)->where('enabled', true)->exists()) {
            return;
        }

        $at = app(AiClock::class)->now();

        foreach (AiCampaign::query()->where('state', AiCampaignState::Active)->pluck('id') as $campaignId) {
            app(RecordCampaignConsultationSignalAction::class)->handle(
                $campaignId,
                AiCampaignConsultationTrigger::NewColony,
                $at,
            );
        }
    }
}
