<?php

namespace Modules\AI\Actions;

use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Models\AiAffectState;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\PsychSimTheoryOfMind;

/**
 * What the account's own engines conclude about one other player, as plain words a line can be coloured by: its mood
 * (affect), how much it trusts or fears them (the relationship), how it reads their intent (theory of mind) and what it
 * remembers of them (the memory driver, ranked against what is about to be said). Numbers never leave: a model that is
 * handed "threat 0.8" quotes it.
 */
class ReadAiInnerStateAction
{
    private const MEMORIES = 3;

    public function __construct(private readonly AiClock $clock)
    {
    }

    /** @return array<string, string|list<string>> empty when the account has nothing on this player */
    public function handle(int $playerId, int $counterpartyId, string $about): array
    {
        $state = array_filter([
            'mood' => $this->mood($playerId),
            'toward_them' => $this->relationship($playerId, $counterpartyId),
            'reads_their_intent' => $this->intent($playerId, $counterpartyId),
            'remembers' => $this->memories($playerId, $counterpartyId, $about),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        return $state;
    }

    private function mood(int $playerId): string|null
    {
        $strongest = AiAffectState::query()->where('player_id', $playerId)->orderByDesc('intensity')->first();
        if ($strongest === null || (float) $strongest->intensity < 0.3) {
            return null;
        }

        $word = match ($strongest->emotion) {
            AiAffectEmotion::Anger => 'angry',
            AiAffectEmotion::Fear => 'nervous',
            AiAffectEmotion::Gratitude => 'grateful',
        };

        return (float) $strongest->intensity >= 0.7 ? 'very ' . $word : $word;
    }

    private function relationship(int $playerId, int $counterpartyId): string|null
    {
        $relationship = AiRelationship::query()->where('player_id', $playerId)->where('other_player_id', $counterpartyId)->first();
        if ($relationship === null) {
            return null;
        }

        return match (true) {
            (float) $relationship->threat >= 0.75 => 'treats them as an enemy',
            (float) $relationship->threat >= 0.4 => 'wary of them',
            (float) $relationship->trust >= 0.5 => 'trusts them',
            default => null,
        };
    }

    private function intent(int $playerId, int $counterpartyId): string|null
    {
        $stance = app(PsychSimTheoryOfMind::class)->stanceToward($playerId, $counterpartyId);

        return $stance === null ? null : ($stance->word() === 'open' ? 'thinks they will keep a deal' : 'thinks they will break a deal');
    }

    /** @return list<string> */
    private function memories(int $playerId, int $counterpartyId, string $about): array
    {
        $facts = app(LongTermMemory::class)->recallRelevantMemories(app()->makeWith(MemoryRecallQuery::class, [
            'playerId' => $playerId,
            'subjectPlayerId' => $counterpartyId,
            'now' => $this->clock->now(),
            'limit' => self::MEMORIES * 5,
            'queryText' => $about,
        ]));

        $sentences = array_unique(array_filter(
            array_map(static fn (array $fact): mixed => $fact['value']['summary'] ?? null, $facts),
            'is_string',
        ));

        return array_slice(array_values($sentences), 0, self::MEMORIES);
    }
}
