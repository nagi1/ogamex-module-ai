<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiRelationship;
use OGame\Models\User;

/**
 * The players this account is at war with: the ones it, or anyone in its alliance, holds at the threat the
 * social replies already refuse at, less its own alliance. Nobody declares the war: whoever raided a member
 * provoked it, and the alliance answers together.
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

        return AiRelationship::query()
            ->whereIn('player_id', $side)
            ->whereNotIn('other_player_id', $side)
            ->where('threat', '>=', self::ENEMY_THREAT)
            ->pluck('other_player_id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            ->all();
    }
}
