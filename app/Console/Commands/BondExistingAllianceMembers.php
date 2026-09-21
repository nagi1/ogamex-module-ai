<?php

namespace Modules\AI\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\User;

/**
 * Raises every existing alliance pair to the friendship floor, once.
 *
 * The alliance bond (`bondAllies`) fires when a member joins, so alliances formed before that
 * change stay flat until membership churn. This command applies the same bond to the pairs that
 * already exist, idempotently: a pair already at or above the floor is left alone, so re-running
 * it never double-bonds.
 */
#[Signature('ai:bond-alliances')]
#[Description('Raise every existing alliance pair to the friendship floor (idempotent).')]
class BondExistingAllianceMembers extends Command
{
    private const TRUST_FLOOR = 0.15;

    private const AFFINITY_FLOOR = 0.20;

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $bonded = 0;

        $membersByAlliance = User::query()
            ->whereNotNull('alliance_id')
            ->whereIn('id', AiProfile::query()->where('enabled', true)->select('player_id'))
            ->get(['id', 'alliance_id'])
            ->groupBy('alliance_id')
            ->map(static fn ($users): array => $users->pluck('id')->map(static fn (int $id): int => $id)->all());

        foreach ($membersByAlliance as $memberIds) {
            foreach ($memberIds as $playerId) {
                foreach ($memberIds as $otherPlayerId) {
                    $bonded += $this->raiseToFloor($playerId, $otherPlayerId, $now);
                }
            }
        }

        $this->info("Raised {$bonded} alliance pair(s) to the friendship floor.");

        return self::SUCCESS;
    }

    private function raiseToFloor(int $playerId, int $otherPlayerId, CarbonImmutable $now): int
    {
        if ($playerId === $otherPlayerId) {
            return 0;
        }

        $relationship = AiRelationship::query()->firstOrNew([
            'player_id' => $playerId,
            'other_player_id' => $otherPlayerId,
        ]);

        $trustDelta = max(0.0, self::TRUST_FLOOR - (float) $relationship->trust);
        $affinityDelta = max(0.0, self::AFFINITY_FLOOR - (float) $relationship->affinity);

        if ($trustDelta <= 0 && $affinityDelta <= 0) {
            return 0;
        }

        app(RecordAiRelationshipInteractionAction::class)->handle(
            $playerId,
            $otherPlayerId,
            0,
            $now,
            trustChange: $trustDelta,
            affinityChange: $affinityDelta,
        );

        return 1;
    }
}
