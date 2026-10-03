<?php

namespace Modules\AI\Domain\Raid;

use OGame\Factories\PlanetServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

/**
 * A foreign planet as the account's own espionage report saw it, never as it stands now.
 *
 * The raid planner and its battle screen used to load the live target: its real ships, defence and
 * stock. A player only knows what the last report said, so the AI was omniscient (never surprised by a
 * rebuilt wall or a fleet that came home) and timid at once (every defended target was simulated at
 * its true strength, so only empty planets passed and LIFE_FIGHTS sat at 9%). This builds a detached
 * copy of the planet row whose units and resources are the report's: the battle engine, the loot
 * formula and the launch ladder then answer "what does my report say", and the dispatch still meets
 * the live planet, which is where surprises, ninjas and lost fleets come from.
 *
 * The copy is never written: it is marked as not existing, so a stray save cannot reach the real row.
 * A report that could not see the fleet or the defence (too few probes) returns null: a player does
 * not raid blind, it sends more probes, which the spy planner's volley already does.
 */
class ReportedPlanet
{
    public function __construct(private PlanetServiceFactory $planetServiceFactory)
    {
    }

    public function of(EspionageReport $report): ?PlanetService
    {
        if ($report->ships === null || $report->defense === null) {
            return null;
        }

        $live = Planet::query()
            ->where('galaxy', (int) $report->planet_galaxy)
            ->where('system', (int) $report->planet_system)
            ->where('planet', (int) $report->planet_position)
            ->where('planet_type', (int) $report->planet_type)
            ->where('destroyed', 0)
            ->first();
        if ($live === null) {
            return null;
        }

        $seen = $live->replicate();
        $seen->id = $live->id;
        $seen->exists = false;

        foreach ([...ObjectService::getShipObjects(), ...ObjectService::getDefenseObjects()] as $object) {
            $seen->{$object->machine_name} = (int) ($report->ships[$object->machine_name] ?? $report->defense[$object->machine_name] ?? 0);
        }

        $resources = $report->resources ?? [];
        $seen->metal = (float) ($resources['metal'] ?? 0);
        $seen->crystal = (float) ($resources['crystal'] ?? 0);
        $seen->deuterium = (float) ($resources['deuterium'] ?? 0);

        return $this->planetServiceFactory->makeFromModel($seen);
    }
}
