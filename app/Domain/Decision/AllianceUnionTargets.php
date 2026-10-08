<?php

namespace Modules\AI\Domain\Decision;

use OGame\Models\EspionageReport;
use OGame\Models\FleetUnion;
use OGame\Models\Message;
use OGame\Models\User;
use OGame\Services\FleetUnionService;

/**
 * The alliance's open ACS attacks this account can join, as the espionage report each is flying on: a player sees a
 * mate's union in the fleet view, reads the shared report and sends his own fleet along. The raid planner still judges
 * the fleet; the attack adapter joins the union when it lands inside the host's delay limit.
 */
class AllianceUnionTargets
{
    /** A report older than this says nothing about the fleet the union will meet. */
    private const REPORT_HOURS = 2;

    /** @return list<int> espionage report ids, the freshest per union */
    public function reports(int $playerId): array
    {
        $allianceId = User::query()->whereKey($playerId)->value('alliance_id');
        if ($allianceId === null) {
            return [];
        }

        $mates = User::query()->where('alliance_id', $allianceId)->pluck('id');
        $unions = app(FleetUnionService::class);
        $reports = [];
        foreach (FleetUnion::query()->whereIn('user_id', $mates)->where('user_id', '!=', $playerId)->where('time_arrival', '>', now()->timestamp)->get() as $union) {
            if ($union->hasReachedMaxFleets() || $union->activeFleetMissions()->where('user_id', $playerId)->exists() || !$unions->canPlayerJoinUnion($union, $playerId)) {
                continue;
            }

            $reportId = EspionageReport::query()
                ->where('planet_galaxy', $union->galaxy_to)
                ->where('planet_system', $union->system_to)
                ->where('planet_position', $union->position_to)
                ->where('planet_type', $union->planet_type_to)
                ->where('created_at', '>=', now()->subHours(self::REPORT_HOURS))
                ->whereIn('id', Message::query()->whereIn('user_id', $mates)->whereNotNull('espionage_report_id')->select('espionage_report_id'))
                ->orderByDesc('id')
                ->value('id');
            if ($reportId !== null) {
                $reports[] = (int) $reportId;
            }
        }

        return $reports;
    }
}
