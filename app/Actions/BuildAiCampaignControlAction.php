<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiOperationLog;

/**
 * The Campaigns tab: the same situation DTO the player-facing board reads, plus the campaigns the
 * declare form can target and the latest control runs. One action, one answer.
 */
class BuildAiCampaignControlAction
{
    private const RECENT_RUNS = 10;

    /**
     * @return array{campaign: array<string, mixed>|null, campaigns: list<array<string, mixed>>, recent: list<array<string, mixed>>}
     */
    public function handle(): array
    {
        return [
            'campaign' => app(SummarizeAiCampaignAction::class)->handle(),
            'campaigns' => $this->campaigns(),
            'recent' => $this->recent(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaigns(): array
    {
        return AiCampaign::query()
            ->whereIn('state', [AiCampaignState::Preparing, AiCampaignState::Active])
            ->orderByDesc('id')
            ->get(['id', 'state', 'starts_at', 'ends_at'])
            ->map(fn (AiCampaign $campaign): array => [
                'id' => $campaign->id,
                'state' => $campaign->state->value,
                'starts_at' => $campaign->starts_at?->toDateTimeString(),
                'ends_at' => $campaign->ends_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recent(): array
    {
        return AiOperationLog::query()
            ->where('operation', 'like', 'campaign:%')
            ->latest('id')
            ->limit(self::RECENT_RUNS)
            ->get(['operation', 'status', 'result', 'created_at'])
            ->map(fn (AiOperationLog $log): array => [
                'operation' => $log->operation,
                'status' => $log->status,
                'result' => $log->result,
                'started_at' => $log->created_at?->toDateTimeString(),
            ])
            ->all();
    }
}
