<?php

namespace Modules\AI\Observers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\RecordObservedAllianceMembershipEndAction;
use Modules\AI\Actions\RecordObservedAllianceMembershipStartAction;
use Modules\AI\Support\AiClock;
use OGame\Models\AllianceMember;

class ObserveCommittedAllianceMembership
{
    public function created(AllianceMember $membership): void
    {
        $membershipId = $membership->id;

        DB::afterCommit(static function () use ($membershipId): void {
            app(RecordObservedAllianceMembershipStartAction::class)->handle($membershipId);
        });
    }

    public function deleted(AllianceMember $membership): void
    {
        $membershipId = $membership->id;
        $subjectPlayerId = $membership->user_id;
        $allianceId = $membership->alliance_id;
        $leftAt = app(AiClock::class)->now();

        DB::afterCommit(static function () use ($membershipId, $subjectPlayerId, $allianceId, $leftAt): void {
            app(RecordObservedAllianceMembershipEndAction::class)->handle(
                $membershipId,
                $subjectPlayerId,
                $allianceId,
                CarbonImmutable::instance($leftAt),
            );
        });
    }
}
