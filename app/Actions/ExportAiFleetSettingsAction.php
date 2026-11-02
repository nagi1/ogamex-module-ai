<?php

namespace Modules\AI\Actions;

use OGame\Enums\FleetSpeedType;
use OGame\Factories\GameMissionFactory;
use OGame\Models\User;
use OGame\Services\CharacterClassService;
use OGame\Services\JumpGateService;
use OGame\Services\ObjectService;
use OGame\Services\OfficerService;
use OGame\Services\PhalanxService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use ReflectionClassConstant;
use ReflectionProperty;

/**
 * Training only (plan/rl/world-sim-spec.md, `fleet`): the universe settings that enter the host's distance, flight
 * time and fuel equations (FleetMissionService), so the simulator's fleet module holds the formulas and none of the
 * numbers. Ship speeds and fuel come from the combat catalogue (ExportAiCombatCatalogueAction).
 */
class ExportAiFleetSettingsAction
{
    public const VERSION = 3;

    /** Moon gear levels tabulated (sensor phalanx range, jump gate cooldown); no account builds either this high. */
    private const MOON_GEAR_LEVELS = 30;

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
            // W15: the dark matter a new account starts with, and each officer's price and effects as the host answers them.
            'dark_matter_initial' => $settings->darkMatterBonus(),
            'officers' => $this->officers($player->getUser()),
            'moon_gear' => $this->moonGear($player),
        ];
    }

    /**
     * Late game: what a moon's sensor phalanx and jump gate do, as the host's services answer for this owner. The phalanx
     * range per level (the class bonus included) and the deuterium a scan takes from the moon; the gate's cooldown per
     * level and the ships it carries, by id.
     *
     * @return array<string, mixed>
     */
    private function moonGear(PlayerService $player): array
    {
        $phalanx = app(PhalanxService::class);
        $gate = app(JumpGateService::class);
        $levels = range(0, self::MOON_GEAR_LEVELS);

        return [
            'phalanx_object' => ObjectService::getObjectByMachineName('sensor_phalanx')->id,
            'gate_object' => ObjectService::getObjectByMachineName('jump_gate')->id,
            'phalanx_range' => array_map(static fn (int $level): int => $level === 0 ? -1 : $phalanx->calculatePhalanxRange($level, $player->getId()), $levels),
            'phalanx_cost' => (new ReflectionClassConstant(PhalanxService::class, 'SCAN_COST'))->getValue(),
            'gate_cooldown' => array_map(static fn (int $level): int => $level === 0 ? 0 : $gate->calculateCooldown($level), $levels),
            'gate_ships' => array_map(static fn (string $name): int => ObjectService::getObjectByMachineName($name)->id, $gate->getTransferableShips()),
        ];
    }

    /**
     * W15: per officer the host sells, its price per duration and what the host's own helpers answer while only that
     * officer is active (the bundle answers with the commanding staff's extras too). The officer is activated on the
     * cached model and never saved, so nothing is written.
     *
     * @return array<string, array<string, mixed>>
     */
    private function officers(User $user): array
    {
        $service = app(OfficerService::class);
        $rows = [];
        foreach (array_keys(OfficerService::COSTS) as $key) {
            $service->clearCache($user);
            $service->getOfficer($user)->activate($key, OfficerService::DURATIONS[0]);
            $rows[$key] = [
                'costs' => array_combine(array_map('strval', OfficerService::DURATIONS), array_map(static fn (int $days): int => $service->getCost($key, $days), OfficerService::DURATIONS)),
                'fleet_slots' => $service->getAdmiralFleetSlots($user) + $service->getCommandingStaffFleetSlots($user),
                'expedition_slots' => $service->getAdditionalExpeditionSlots($user),
                'research_multiplier' => $service->getResearchTimeMultiplier($user),
                'espionage' => $service->getAdditionalEspionageLevels($user) + $service->getCommandingStaffEspionageLevels($user),
                // The bundle is the officer that makes every other one active; the host says which by answering, not by name.
                'bundle' => array_filter(array_keys(OfficerService::COSTS), static fn (string $other): bool => $other !== $key && !$service->isActive($user, $other)) === [],
            ];
        }
        $service->clearCache($user);

        return $rows;
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
