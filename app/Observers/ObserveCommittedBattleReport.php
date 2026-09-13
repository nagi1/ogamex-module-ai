<?php

namespace Modules\AI\Observers;

use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\RecordObservedBattleReportAction;
use OGame\Models\BattleReport;

/**
 * Records the battles a participating AI could legally read.
 *
 * The stored report is the trigger, never `BattleResolved`: that event fires before the
 * result is durable and carries identifiers only, so a listener could observe a battle
 * that is later rolled back. The committed row is the fact.
 */
class ObserveCommittedBattleReport
{
    public function created(BattleReport $battleReport): void
    {
        $battleReportId = $battleReport->id;

        // A battle can occur before its transaction commits; cognition must not.
        DB::afterCommit(static function () use ($battleReportId): void {
            app(RecordObservedBattleReportAction::class)->handle($battleReportId);
        });
    }
}
