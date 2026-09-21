<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Operability\AiProgressBoard;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;

/**
 * The unified per-account view the console has been missing: one row per enabled profile with its
 * window growth, its current work, its last action and the alerts that explain why a row is on
 * top. Growth comes from the same score loop the pilot report already runs, never a second
 * computation of the growth rule.
 */
class BuildAiProgressBoardAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $days): AiProgressBoard
    {
        $window = max(1, $days);
        $now = $this->clock->now();
        $score = app(BuildAiPilotReportAction::class)->scoreFor($window);
        $deltas = collect($score->perAccountDeltas)->keyBy('player_id');

        $profiles = AiProfile::query()->where('enabled', true)->get(['player_id', 'archetype', 'skill_band']);
        $playerIds = $profiles->pluck('player_id')->all();

        $dueByPlayer = AiWorkItem::query()
            ->whereIn('player_id', $playerIds)
            ->whereIn('state', [AiWorkState::Pending, AiWorkState::Retry])
            ->where('due_at', '<=', $now)
            ->get(['player_id'])
            ->countBy('player_id');
        $inFlightByPlayer = AiWorkItem::query()
            ->whereIn('player_id', $playerIds)
            ->where('state', AiWorkState::Leased)
            ->where('lease_until', '>', $now)
            ->get(['player_id'])
            ->countBy('player_id');
        $stuckByPlayer = AiWorkItem::query()
            ->whereIn('player_id', $playerIds)
            ->where('state', AiWorkState::Leased)
            ->where('lease_until', '<', $now)
            ->get(['player_id'])
            ->countBy('player_id');
        $lastReceiptByPlayer = AiActionReceipt::query()
            ->whereIn('player_id', $playerIds)
            ->orderByDesc('id')
            ->get(['player_id', 'action_type', 'state', 'created_at'])
            ->unique('player_id')
            ->keyBy('player_id');

        $rows = $profiles
            ->map(fn (AiProfile $profile): array => $this->row($profile, $deltas, $dueByPlayer, $inFlightByPlayer, $stuckByPlayer, $lastReceiptByPlayer))
            ->sortBy(static fn (array $row): string => $row['last_action_at'] ?? '9999-12-31')
            ->sortByDesc(static fn (array $row): int => count($row['alerts']))
            ->values()
            ->all();

        return app()->makeWith(AiProgressBoard::class, ['days' => $window, 'rows' => $rows]);
    }

    /**
     * @param \Illuminate\Support\Collection<int, mixed> $deltas
     * @param \Illuminate\Support\Collection<int, int> $dueByPlayer
     * @param \Illuminate\Support\Collection<int, int> $inFlightByPlayer
     * @param \Illuminate\Support\Collection<int, int> $stuckByPlayer
     * @param \Illuminate\Support\Collection<int, AiActionReceipt> $lastReceiptByPlayer
     * @return array<string, mixed>
     */
    private function row(
        AiProfile $profile,
        \Illuminate\Support\Collection $deltas,
        \Illuminate\Support\Collection $dueByPlayer,
        \Illuminate\Support\Collection $inFlightByPlayer,
        \Illuminate\Support\Collection $stuckByPlayer,
        \Illuminate\Support\Collection $lastReceiptByPlayer,
    ): array {
        $playerId = $profile->player_id;
        $delta = $deltas->get($playerId)['delta'] ?? null;
        $last = $lastReceiptByPlayer->get($playerId);
        $alerts = [];

        if ($delta === 0) {
            $alerts[] = 'no_growth';
        }

        if ($last === null) {
            $alerts[] = 'no_action';
        }

        if (($stuckByPlayer[$playerId] ?? 0) > 0) {
            $alerts[] = 'stuck';
        }

        return [
            'player_id' => $playerId,
            'archetype' => $profile->archetype->name,
            'skill_band' => $profile->skill_band->name,
            'due_work' => $dueByPlayer[$playerId] ?? 0,
            'in_flight' => $inFlightByPlayer[$playerId] ?? 0,
            'stuck' => $stuckByPlayer[$playerId] ?? 0,
            'delta' => $delta,
            'last_action' => $last?->action_type?->name,
            'last_state' => $last?->state?->name,
            'last_action_at' => $last?->created_at?->toDateTimeString(),
            'alerts' => $alerts,
        ];
    }
}
