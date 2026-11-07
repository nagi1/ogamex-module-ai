<?php

namespace Modules\AI\Tests\Support;

use Illuminate\Support\Facades\DB;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\MessageService;
use OGame\Services\PlanetService;

/**
 * The war-world raid stories' props, beside the Situation kit: farms on any body and the account's own reports on them,
 * written field by field the way the host's EspionageMission writes one. Nothing here names an object.
 */
final class RaidStory
{
    /** A foreign planet whose owner stopped logging in $daysQuiet days ago, holding stock a raid is worth. */
    public static function farm(object $test, int $daysQuiet = 10, int $metal = 300_000, int $crystal = 150_000): PlanetService
    {
        $farm = (fn (): PlanetService => $this->createForeignPlanet())->call($test);
        $farm->addResources(new Resources($metal, $crystal, 0));
        self::quiet($farm, $daysQuiet);

        return $farm;
    }

    /** The owner of $body and its bodies last seen $days ago. */
    public static function quiet(PlanetService $body, int $days): void
    {
        DB::table('users')->where('id', $body->getPlayer()->getId())->update(['time' => now()->subDays($days)->timestamp]);
        Planet::query()->where('user_id', $body->getPlayer()->getId())->update(['time_last_update' => now()->subDays($days)->timestamp]);
        $body->reloadPlanet();
    }

    /**
     * The account's own report on $body as it stands now. $hideShips and $hideDefence leave a section unread, as a
     * volley too small for the espionage gap does.
     */
    public static function report(object $test, PlanetService $body, bool $hideShips = false, bool $hideDefence = false): EspionageReport
    {
        $owner = $body->getPlayer();
        $at = $body->getPlanetCoordinates();
        $report = new EspionageReport();
        $report->planet_galaxy = $at->galaxy;
        $report->planet_system = $at->system;
        $report->planet_position = $at->position;
        $report->planet_type = $body->getPlanetType()->value;
        $report->planet_user_id = $owner->getId();
        $report->player_info = ['player_id' => (string) $owner->getId(), 'player_name' => $owner->getUsername()];
        $report->resources = ['metal' => (int) $body->metal()->get(), 'crystal' => (int) $body->crystal()->get(), 'deuterium' => (int) $body->deuterium()->get(), 'energy' => 0];
        $report->ships = $hideShips ? null : $body->getShipUnits()->toArray();
        $report->defense = $hideShips || $hideDefence ? null : $body->getDefenseUnits()->toArray();
        $report->buildings = $body->getBuildingArray();
        $report->research = $owner->getResearchArray();
        $report->counter_espionage_chance = 0;
        $report->save();
        app(MessageService::class)->sendEspionageReportMessageToPlayer((fn (): PlanetService => $this->planetService)->call($test)->getPlayer(), $report->id);

        return $report;
    }

    /** $count attacks the account already flew at $targetPlanetId, $hoursAgo hours ago, by the host's own mission rows. */
    public static function attacked(int $playerId, int $originPlanetId, int $targetPlanetId, int $count, int $hoursAgo = 8): void
    {
        for ($attack = 0; $attack < $count; $attack++) {
            $mission = new FleetMission();
            $mission->user_id = $playerId;
            $mission->planet_id_from = $originPlanetId;
            $mission->planet_id_to = $targetPlanetId;
            $mission->mission_type = 1;
            $mission->time_departure = now()->subHours($hoursAgo)->timestamp;
            $mission->time_arrival = now()->subHours($hoursAgo - 1)->timestamp;
            $mission->time_arrival_ms = 0;
            $mission->processed = 1;
            $mission->canceled = 0;
            $mission->save();
        }
    }
}
