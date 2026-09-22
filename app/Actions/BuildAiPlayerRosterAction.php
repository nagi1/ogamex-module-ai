<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Collection;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use OGame\Models\User;

/**
 * The Players roster: one row per account, enabled and stopped alike, ordered so a failure is
 * first. Growth comes from the same score loop the pilot report runs, never a second computation
 * of the growth rule. Search and filters are applied over the rows in memory, which is bounded by
 * the universe profile cap rather than by the table.
 */
class BuildAiPlayerRosterAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, search: string, state: string, alerts_only: bool}
     */
    public function handle(int $days, string $search = '', string $state = 'all', bool $alertsOnly = false): array
    {
        $window = max(1, $days);
        $now = $this->clock->now();
        $score = app(BuildAiPilotReportAction::class)->scoreFor($window);
        $deltas = collect($score->perAccountDeltas)->keyBy('player_id');

        $profiles = AiProfile::query()->orderBy('player_id')->get(['player_id', 'archetype', 'skill_band', 'enabled']);
        $playerIds = $profiles->pluck('player_id')->all();
        $usernames = User::query()->whereIn('id', $playerIds)->pluck('username', 'id');

        $dueByPlayer = $this->countsByPlayer($playerIds, [AiWorkState::Pending, AiWorkState::Retry], 'due_at', '<=', $now);
        $inFlightByPlayer = $this->countsByPlayer($playerIds, [AiWorkState::Leased], 'lease_until', '>', $now);
        $stuckByPlayer = $this->countsByPlayer($playerIds, [AiWorkState::Leased], 'lease_until', '<', $now);
        $lastReceiptByPlayer = AiActionReceipt::query()
            ->whereIn('player_id', $playerIds)
            ->orderByDesc('id')
            ->get(['player_id', 'action_type', 'state', 'created_at'])
            ->unique('player_id')
            ->keyBy('player_id');

        $rows = $profiles
            ->map(fn (AiProfile $profile): array => $this->row($profile, $usernames, $deltas, $dueByPlayer, $inFlightByPlayer, $stuckByPlayer, $lastReceiptByPlayer))
            ->when($state !== 'all', fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => $row['enabled'] === ($state === 'enabled')))
            ->when($alertsOnly, fn (Collection $rows): Collection => $rows->filter(static fn (array $row): bool => $row['alerts'] !== []))
            ->when($search !== '', fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => str_contains(strtolower((string) $row['username']), strtolower($search)) || (string) $row['player_id'] === $search))
            ->sortBy(static fn (array $row): string => $row['last_action_at'] ?? '9999-12-31')
            ->sortByDesc(static fn (array $row): int => count($row['alerts']))
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'search' => $search,
            'state' => $state,
            'alerts_only' => $alertsOnly,
        ];
    }

    /**
     * @param list<int> $playerIds
     * @param list<AiWorkState> $states
     * @return Collection<int, int>
     */
    private function countsByPlayer(array $playerIds, array $states, string $column, string $operator, mixed $value): Collection
    {
        return AiWorkItem::query()
            ->whereIn('player_id', $playerIds)
            ->whereIn('state', $states)
            ->where($column, $operator, $value)
            ->get(['player_id'])
            ->countBy('player_id');
    }

    /**
     * @param Collection<int, string> $usernames
     * @param Collection<int, mixed> $deltas
     * @param Collection<int, int> $dueByPlayer
     * @param Collection<int, int> $inFlightByPlayer
     * @param Collection<int, int> $stuckByPlayer
     * @param Collection<int, AiActionReceipt> $lastReceiptByPlayer
     * @return array<string, mixed>
     */
    private function row(
        AiProfile $profile,
        Collection $usernames,
        Collection $deltas,
        Collection $dueByPlayer,
        Collection $inFlightByPlayer,
        Collection $stuckByPlayer,
        Collection $lastReceiptByPlayer,
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
            'username' => $usernames->get($playerId) ?? (string) $playerId,
            'archetype' => $profile->archetype->name,
            'skill_band' => $profile->skill_band->name,
            'enabled' => $profile->enabled,
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
