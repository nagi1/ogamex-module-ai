<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiRelationship;
use OGame\Models\User;

/**
 * The players this account is at war with: the ones it, or anyone in its alliance, holds at the threat the
 * social replies already refuse at, less its own alliance, plus every member of an alliance its own is at war
 * with, less any it holds a non-aggression pact with (AdvanceAiAlliancePoliticsAction, alliance-politics.md).
 */
class Enemies
{
    /** The threat at which an account refuses a ceasefire or an apology (BuildAuthoredSocialReplyAction). */
    public const float ENEMY_THREAT = 0.75;

    /** @return array<int, true> */
    public function of(int $playerId): array
    {
        $allianceId = User::query()->whereKey($playerId)->value('alliance_id');
        $side = $allianceId === null
            ? [$playerId]
            : User::query()->where('alliance_id', $allianceId)->pluck('id')->push($playerId)->unique()->all();

        $grudges = AiRelationship::query()
            ->whereIn('player_id', $side)
            ->whereNotIn('other_player_id', $side)
            ->where('threat', '>=', self::ENEMY_THREAT)
            ->pluck('other_player_id');

        if ($allianceId !== null) {
            $grudges = $grudges->merge($this->membersOf((int) $allianceId, AiAllianceStance::War))
                ->diff($this->membersOf((int) $allianceId, AiAllianceStance::Nap));
        }

        return $grudges->mapWithKeys(static fn ($id): array => [(int) $id => true])->all();
    }

    /**
     * The players the account never scouts or raids: its alliance mates and the members of any alliance its own holds a
     * non-aggression pact with. Hitting a mate, or a partner mid-pact, is what gets a player kicked or a pact broken.
     *
     * @return list<int>
     */
    public function spared(int $playerId): array
    {
        $allianceId = User::query()->whereKey($playerId)->value('alliance_id');
        if ($allianceId === null) {
            return [];
        }

        return User::query()->where('alliance_id', $allianceId)->whereKeyNot($playerId)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->merge($this->membersOf((int) $allianceId, AiAllianceStance::Nap))->values()->all();
    }

    /** @return list<int> */
    private function membersOf(int $allianceId, AiAllianceStance $stance): array
    {
        return User::query()->whereIn('alliance_id', AiAllianceRelation::counterparts($allianceId, $stance))
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
