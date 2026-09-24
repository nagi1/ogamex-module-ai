<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Collection;
use Modules\AI\Enums\AiToMStance;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\PsychSimTheoryOfMind;
use OGame\Models\BuddyRequest;
use OGame\Services\BuddyService;

/**
 * Answers pending buddy requests the way a player does: an account accepts a request only from
 * someone it already knows — a recorded relationship or a prior social exchange — and leaves a
 * stranger's request pending for a later look. The host owns the buddy list; this only decides.
 */
class ReviewAiBuddyRequestsAction
{
    public function handle(): int
    {
        $accepted = 0;

        foreach (AiProfile::query()->where('enabled', true)->pluck('player_id') as $playerId) {
            foreach ($this->pendingRequests((int) $playerId) as $request) {
                if (!$this->knowsContact((int) $playerId, (int) $request->sender_user_id)) {
                    continue;
                }

                if ($this->readsAsExploitative((int) $playerId, (int) $request->sender_user_id)) {
                    continue;
                }

                if (app(BuddyService::class)->acceptRequest($request->id, (int) $playerId)) {
                    $accepted++;
                }
            }
        }

        return $accepted;
    }

    /**
     * @return Collection<int, BuddyRequest>
     */
    private function pendingRequests(int $playerId): Collection
    {
        return app(BuddyService::class)->getReceivedRequests($playerId)
            ->filter(static fn (BuddyRequest $request): bool => $request->status === BuddyRequest::STATUS_PENDING);
    }

    private function knowsContact(int $playerId, int $otherPlayerId): bool
    {
        return AiRelationship::query()
                ->where(fn ($query) => $query
                    ->where('player_id', $playerId)->where('other_player_id', $otherPlayerId)
                    ->orWhere('player_id', $otherPlayerId)->where('other_player_id', $playerId))
                ->exists()
            || AiSocialExchange::query()
                ->where(fn ($query) => $query
                    ->where('player_id', $playerId)->where('counterparty_player_id', $otherPlayerId)
                    ->orWhere('player_id', $otherPlayerId)->where('counterparty_player_id', $playerId))
                ->exists();
    }

    /**
     * A known contact is not enough when the account models the sender as exploitative: a wary
     * account leaves that request pending instead of welcoming a likely raider.
     */
    private function readsAsExploitative(int $playerId, int $otherPlayerId): bool
    {
        return app(PsychSimTheoryOfMind::class)->stanceToward($playerId, $otherPlayerId) === AiToMStance::Defect;
    }
}
