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

class RecordObservedAllianceMembershipEndAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $membershipId, int $subjectPlayerId, int $allianceId, CarbonImmutable $occurredAt): int
    {
        if (AllianceMember::query()->whereKey($membershipId)->exists()) {
            return 0;
        }

        return DB::transaction(fn (): int => $this->recordMembershipFacts(
            $membershipId,
            $subjectPlayerId,
            $allianceId,
            $occurredAt,
        ));
    }

    private function recordMembershipFacts(int $membershipId, int $subjectPlayerId, int $allianceId, CarbonImmutable $occurredAt): int
    {
        $playerIds = AiProfile::query()
            ->where('enabled', true)
            ->where(function ($query) use ($allianceId, $subjectPlayerId): void {
                $query->where('player_id', $subjectPlayerId)
                    ->orWhereIn('player_id', User::query()->where('alliance_id', $allianceId)->select('id'));
            })
            ->pluck('player_id');

        $recordedCount = 0;

        foreach ($playerIds as $playerId) {
            $observation = AiObservation::query()->firstOrCreate([
                'player_id' => $playerId,
                'source_type' => AiObservationSource::AllianceMembershipLeft,
                'source_id' => $membershipId,
            ], [
                'kind' => AiObservationKind::AllianceMembershipLeft,
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
                ['alliance_id' => null],
                $observation->id,
                $occurredAt,
            );

            $recordedCount++;
        }

        return $recordedCount;
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
