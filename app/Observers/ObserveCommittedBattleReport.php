<?php

namespace Modules\AI\Observers;

use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\RecordObservedBattleReportAction;
use Modules\AI\Actions\ResolveAiCampaignObjectiveFromBattleReportAction;
use OGame\Models\BattleReport;

/**
 * Runs the module's committed-battle side effects.
 *
 * The stored report is the trigger, never `BattleResolved`: that event fires before the
 * result is durable and carries identifiers only, so a listener could observe a battle
 * that is later rolled back. The committed row is the fact. Two consumers share it: the
 * observation reducer for the AIs that took part, and the campaign objective resolver for
 * a declared stronghold that fell to a coalition victory.
 */
class ObserveCommittedBattleReport
{
    public function created(BattleReport $battleReport): void
    {
        $battleReportId = $battleReport->id;

        // A battle can occur before its transaction commits; cognition must not.
        DB::afterCommit(static function () use ($battleReportId): void {
            app(RecordObservedBattleReportAction::class)->handle($battleReportId);
            app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($battleReportId);
        });
    }
}
