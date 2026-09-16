<?php

namespace Modules\AI\Actions;

use Modules\AI\Models\AiCampaignObjective;
use OGame\Models\BattleReport;
use OGame\Models\Planet;

/**
 * Marks a declared campaign stronghold complete once a committed battle report records an
 * attacker victory against its planet.
 *
 * Victory mirrors the host's own battle-report winner: the last round's surviving ships
 * decide, an empty round list means the attacker won without a fight, and a battle where
 * both sides withdrew is never a win. A draw, a retreat and a defender win are declined,
 * so an objective only completes from a coalition victory at the stronghold's planet.
 * Winning does not capture or delete the planet, and an already-complete objective is
 * never credited twice.
 */
class ResolveAiCampaignObjectiveFromBattleReportAction
{
    public function handle(int $battleReportId): int
    {
        /** @var BattleReport|null $battleReport */
        $battleReport = BattleReport::query()->find($battleReportId);

        if ($battleReport === null || !$this->attackerWon($battleReport)) {
            return 0;
        }

        $planetId = Planet::query()
            ->where('galaxy', $battleReport->planet_galaxy)
            ->where('system', $battleReport->planet_system)
            ->where('planet', $battleReport->planet_position)
            ->where('planet_type', $battleReport->planet_type)
            ->value('id');

        if ($planetId === null) {
            return 0;
        }

        return AiCampaignObjective::query()
            ->where('planet_id', $planetId)
            ->whereNull('completed_at')
            ->update(['completed_at' => $battleReport->created_at ?? now()]);
    }

    private function attackerWon(BattleReport $battleReport): bool
    {
        // Both sides withdrawing is a retreat without combat, never a win, even though
        // the host would report no rounds and therefore no defender survivors.
        $general = is_array($battleReport->general) ? $battleReport->general : [];
        $retreat = is_array($general['tactical_retreat'] ?? null) ? $general['tactical_retreat'] : [];

        if (($retreat['attacker_also_retreated'] ?? false) === true) {
            return false;
        }

        $rounds = is_array($battleReport->rounds) ? $battleReport->rounds : [];

        if ($rounds === []) {
            return true;
        }

        $lastRound = $rounds[array_key_last($rounds)];

        return $this->amount($lastRound['attacker_ships'] ?? null) > 0
            && $this->amount($lastRound['defender_ships'] ?? null) === 0;
    }

    private function amount(mixed $units): int
    {
        $total = 0;

        if (!is_array($units)) {
            return $total;
        }

        foreach ($units as $amount) {
            $total += is_numeric($amount) ? (int) $amount : 0;
        }

        return $total;
    }
}
