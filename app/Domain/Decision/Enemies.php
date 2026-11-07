<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Contracts\AllianceTreaties;
use Modules\AI\Domain\Social\SocialMinds;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiRelationship;
use OGame\Models\User;

/**
 * The players this account is at war with: the ones it, or anyone in its alliance, holds at the threat the
 * social replies already refuse at, less its own alliance, plus every member of an alliance its own is at war
 * with, less any it holds a non-aggression pact with or is bound to as vassal or overlord (AdvanceAiAlliancePoliticsAction,
 * alliance-politics.md).
 */
class Enemies
{
    /** The threat at which an account refuses a ceasefire or an apology (BuildAuthoredSocialReplyAction). */
    public const float ENEMY_THREAT = 0.75;

    public function __construct(private readonly AllianceTreaties $treaties)
    {
    }

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
                ->diff($this->membersOf((int) $allianceId, AiAllianceStance::Nap))->diff($this->vassalBound((int) $allianceId));
        }

        return $grudges->diff($this->truces($playerId))->mapWithKeys(static fn ($id): array => [(int) $id => true])->all();
    }

    /**
     * The players the account never scouts or raids: its alliance mates, the members of any alliance its own holds a
     * non-aggression pact with or is bound to by tribute (a vassal pays, its overlord protects), and anyone it agreed a
     * ceasefire with that still runs. Hitting a mate, a partner mid-pact or a player mid-truce is what gets a player
     * kicked, a pact broken or a name passed around as a liar.
     *
     * @return list<int>
     */
    public function spared(int $playerId): array
    {
        $allianceId = User::query()->whereKey($playerId)->value('alliance_id');
        if ($allianceId === null) {
            return $this->truces($playerId);
        }

        return User::query()->where('alliance_id', $allianceId)->whereKeyNot($playerId)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->merge($this->membersOf((int) $allianceId, AiAllianceStance::Nap))
            ->merge($this->vassalBound((int) $allianceId))
            ->merge($this->truces($playerId))->unique()->values()->all();
    }

    /** @return list<int> */
    private function truces(int $playerId): array
    {
        return AiCommitment::query()->heldCeasefire($playerId)->get(['player_id', 'counterparty_player_id'])
            ->map(static fn (AiCommitment $truce): int => $truce->player_id === $playerId ? $truce->counterparty_player_id : $truce->player_id)
            ->unique()->values()->all();
    }

    /** @return list<int> */
    private function vassalBound(int $allianceId): array
    {
        return array_values(User::query()->whereIn('alliance_id', app(SocialMinds::class)->vassalBonds($allianceId))
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    /** @return list<int> */
    private function membersOf(int $allianceId, AiAllianceStance $stance): array
    {
        return User::query()->whereIn('alliance_id', $this->treaties->counterparts($allianceId, $stance))
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
