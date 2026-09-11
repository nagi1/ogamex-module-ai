<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Models\AiMemoryFact;

class FindCurrentAiMemoryFactsAction
{
    /**
     * @return Collection<int, AiMemoryFact>
     */
    public function handle(int $playerId, int $subjectPlayerId, AiMemoryPredicate $predicate, CarbonImmutable $now): Collection
    {
        return AiMemoryFact::query()
            ->where('player_id', $playerId)
            ->where('subject_player_id', $subjectPlayerId)
            ->where('predicate', $predicate)
            ->where('valid_from', '<=', $now)
            ->whereNull('redacted_at')
            ->where(function ($query) use ($now): void {
                $query->whereNull('valid_to')->orWhere('valid_to', '>', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->orderByDesc('valid_from')
            ->get();
    }
}
