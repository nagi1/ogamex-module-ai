<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiProfile;
use OGame\Models\Planet;

/**
 * Keeps one cooperative campaign running on the cohort without an operator (CAMPAIGN-001).
 *
 * The campaign lane had never run because opening and declaring were operator controls only. When no
 * campaign is open or preparing, this opens a week-long one and declares the strongholds the AI
 * accounts would defend: each enabled account's best-developed planet, capped at a handful so the
 * campaign has a front rather than the whole cohort. The existing advance command moves it through
 * its states, and the contribution and consultation rules hang off it from there.
 */
class AutoRunAiCampaignAction
{
    private const DURATION_DAYS = 7;

    private const MAX_STRONGHOLDS = 5;

    public function handle(): ?AiCampaign
    {
        $running = AiCampaign::query()
            ->whereIn('state', [AiCampaignState::Preparing, AiCampaignState::Active])
            ->exists();
        if ($running) {
            return null;
        }

        $accounts = AiProfile::query()->where('enabled', true)->orderBy('player_id')->pluck('player_id');
        if ($accounts->isEmpty()) {
            return null;
        }

        $now = CarbonImmutable::now();
        $campaign = app(OpenAiCampaignAction::class)->handle($now, $now->addDays(self::DURATION_DAYS));

        $strongholds = Planet::query()
            ->whereIn('user_id', $accounts)
            ->orderByDesc('field_current')
            ->limit(self::MAX_STRONGHOLDS)
            ->pluck('id');
        foreach ($strongholds as $planetId) {
            app(DeclareAiCampaignObjectiveAction::class)->handle($campaign->id, (int) $planetId);
        }

        return $campaign;
    }
}
