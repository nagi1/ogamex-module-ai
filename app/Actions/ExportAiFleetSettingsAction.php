<?php

namespace Modules\AI\Actions;

use OGame\Enums\FleetSpeedType;
use OGame\Factories\GameMissionFactory;
use OGame\Services\CharacterClassService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use ReflectionProperty;

/**
 * Training only (plan/rl/world-sim-spec.md, `fleet`): the universe settings that enter the host's distance, flight
 * time and fuel equations (FleetMissionService), so the simulator's fleet module holds the formulas and none of the
 * numbers. Ship speeds and fuel come from the combat catalogue (ExportAiCombatCatalogueAction).
 */
class ExportAiFleetSettingsAction
{
    public const VERSION = 1;

    /**
     * @param  PlayerService  $player  the owner whose class changes fuel use
     * @return array<string, mixed>
     */
    public function handle(PlayerService $player): array
    {
        $settings = app(SettingsService::class);

        return [
            'version' => self::VERSION,
            'player_id' => $player->getId(),
            'number_of_galaxies' => $settings->numberOfGalaxies(),
            'number_of_systems' => $settings->numberOfSystems(),
            'ignore_empty_systems' => (bool) $settings->ignoreEmptySystemsOn(),
            'ignore_inactive_systems' => (bool) $settings->ignoreInactiveSystemsOn(),
            // Fuel is priced on this speed, not the mission's (FleetMissionService::calculateConsumption).
            'fleet_speed' => $settings->fleetSpeed(),
            'mission_fleet_speed' => $this->missionFleetSpeeds($settings),
            'deuterium_consumption' => $settings->deuteriumConsumption(),
            'class_consumption_multiplier' => app(CharacterClassService::class)->getDeuteriumConsumptionMultiplier($player->getUser()),
        ];
    }

    /**
     * The fleet speed each mission type flies at, as FleetMissionService::calculateFleetMissionDuration picks it; a
     * mission that declares no speed type (the missile strike) has its own timing and is left out.
     *
     * @return array<int, int>
     */
    private function missionFleetSpeeds(SettingsService $settings): array
    {
        $speeds = [];
        foreach (GameMissionFactory::getMissionClasses() as $typeId => $class) {
            if (!(new ReflectionProperty($class, 'fleetSpeedType'))->isInitialized()) {
                continue;
            }
            $speeds[$typeId] = match ($class::getFleetSpeedType()) {
                FleetSpeedType::war => $settings->fleetSpeedWar(),
                FleetSpeedType::holding => $settings->fleetSpeedHolding(),
                FleetSpeedType::peaceful => $settings->fleetSpeedPeaceful(),
            };
        }

        return $speeds;
    }
}
