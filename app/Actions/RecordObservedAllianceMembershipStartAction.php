<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiMemoryFact;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Models\AllianceMember;
use OGame\Models\User;

class RecordObservedAllianceMembershipStartAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $membershipId): int
    {
        /** @var AllianceMember|null $membership */
        $membership = AllianceMember::query()->find($membershipId);

        if ($membership === null) {
            return 0;
        }

        /** @var User|null $member */
        $member = User::query()->find($membership->user_id);

        if ($member?->alliance_id !== $membership->alliance_id) {
            return 0;
        }

        $occurredAt = CarbonImmutable::instance($membership->joined_at ?? $this->clock->now());

        return DB::transaction(fn (): int => $this->recordMembershipFacts(
            $membership->id,
            $membership->user_id,
            $membership->alliance_id,
            $occurredAt,
        ));
    }

    private function recordMembershipFacts(int $membershipId, int $subjectPlayerId, int $allianceId, CarbonImmutable $occurredAt): int
    {
        $playerIds = AiProfile::query()
            ->where('enabled', true)
            ->whereIn('player_id', User::query()->where('alliance_id', $allianceId)->select('id'))
            ->pluck('player_id');

        $recordedCount = 0;

        foreach ($playerIds as $playerId) {
            $observation = AiObservation::firstOrCreateAtomically([
                'player_id' => $playerId,
                'source_type' => AiObservationSource::AllianceMembershipJoined,
                'source_id' => $membershipId,
            ], [
                'kind' => AiObservationKind::AllianceMembershipJoined,
                'subject_player_id' => $subjectPlayerId,
                'source_time' => $occurredAt,
                'observed_at' => $this->clock->now(),
            ]);

            if (!$observation->wasRecentlyCreated) {
                continue;
            }

            $this->closePreviousMembershipFacts($playerId, $subjectPlayerId, $observation->id, $occurredAt);

            app(RecordAiMemoryFactAction::class)->handle(
                $playerId,
                $subjectPlayerId,
                AiMemoryPredicate::AllianceMembership,
                AiMemoryEvidenceKind::Verified,
                ['alliance_id' => $allianceId],
                $observation->id,
                $occurredAt,
            );

            $this->bondAllies($playerId, $subjectPlayerId, $observation->id, $occurredAt);

            $recordedCount++;
        }

        return $recordedCount;
    }

    /**
     * Joining an alliance is how a bloc forms: the joiner and each co-member grow to trust
     * and like one another. The alliance is itself the standing agreement, so this is the one
     * cooperative path that grants trust without a separately kept promise. Both directions
     * are written; the self-guard in the relationship action skips the joiner's own row.
     *
     * ponytail: two members joining in one batch double-bond (each membership event sees both
     * as current members). Bounded and harmless — real members join a session apart — so there
     * is no pair-dedupe; add a membership-scoped bond key only if a batch seeder ever appears.
     */
    private function bondAllies(int $playerId, int $subjectPlayerId, int $observationId, CarbonImmutable $occurredAt): void
    {
        app(RecordAiRelationshipInteractionAction::class)->handle(
            $playerId,
            $subjectPlayerId,
            $observationId,
            $occurredAt,
            trustChange: 0.15,
            affinityChange: 0.20,
        );

        app(RecordAiRelationshipInteractionAction::class)->handle(
            $subjectPlayerId,
            $playerId,
            $observationId,
            $occurredAt,
            trustChange: 0.15,
            affinityChange: 0.20,
        );
    }

    private function closePreviousMembershipFacts(int $playerId, int $subjectPlayerId, int $sourceObservationId, CarbonImmutable $occurredAt): void
    {
        AiMemoryFact::query()
            ->where('player_id', $playerId)
            ->where('subject_player_id', $subjectPlayerId)
            ->where('predicate', AiMemoryPredicate::AllianceMembership)
            ->where('source_observation_id', '!=', $sourceObservationId)
            ->where('valid_from', '<=', $occurredAt)
            ->whereNull('valid_to')
            ->update(['valid_to' => $occurredAt]);
    }
}
