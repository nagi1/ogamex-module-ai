<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignObjective;
use Modules\AI\Models\AiProfile;
use OGame\Models\Highscore;
use OGame\Models\Planet;

/**
 * The coalition-facing campaign situation log, assembled from what the host and the campaign
 * already record: objective progress (completed strongholds of the announced set), the faction's
 * momentum counter (the influence bar), and both sides' losses as the host's own write-time
 * military-loss aggregates. One bounded pass; no generative calls, no new collection.
 *
 * @return array<string, mixed>|null
 */
class SummarizeAiCampaignAction
{
    public function handle(): ?array
    {
        $campaign = AiCampaign::query()
            ->whereIn('state', [
                AiCampaignState::Preparing,
                AiCampaignState::Active,
                AiCampaignState::Resolved,
                AiCampaignState::Failed,
                AiCampaignState::FactionWon,
            ])
            ->orderByDesc('id')
            ->first();

        if ($campaign === null) {
            return null;
        }

        $objectives = $campaign->objectives()->orderBy('id')->get();
        $coordinates = Planet::query()
            ->whereIn('id', $objectives->pluck('planet_id'))
            ->get(['id', 'galaxy', 'system', 'planet'])
            ->keyBy('id');

        $strongholds = $objectives->map(fn (AiCampaignObjective $objective): array => [
            'coordinates' => $this->coordinates($coordinates->get($objective->planet_id)),
            'completed' => $objective->completed_at !== null,
        ])->all();

        $factionIds = AiProfile::query()->where('enabled', true)->pluck('player_id');

        return [
            'state' => $campaign->state,
            'startsAt' => $campaign->starts_at,
            'endsAt' => $campaign->ends_at,
            'completed' => $objectives->whereNotNull('completed_at')->count(),
            'total' => $objectives->count(),
            'factionMomentum' => (int) $campaign->faction_momentum,
            'factionLosses' => (int) Highscore::query()->whereIn('player_id', $factionIds)->sum('military_lost'),
            'coalitionLosses' => (int) Highscore::query()->whereNotIn('player_id', $factionIds)->sum('military_lost'),
            'strongholds' => $strongholds,
        ];
    }

    private function coordinates(?Planet $planet): string
    {
        if ($planet === null) {
            return '—';
        }

        return sprintf('%d:%d:%d', $planet->galaxy, $planet->system, $planet->planet);
    }
}
