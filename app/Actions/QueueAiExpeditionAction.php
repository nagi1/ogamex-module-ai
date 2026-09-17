<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiExpedition;
use Modules\AI\Domain\Decision\QueueableExpeditionPlanner;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\ExpeditionMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\PlayerService;

/**
 * Module-owned adapter over the host's expedition mission.
 *
 * The slot-16 coordinate, the slot budget and the outcome table are the host's;
 * the module adds the never-fleetsave refusal (EXP-001): a single small
 * disposable hull, never the fleet the account depends on.
 */
class QueueAiExpeditionAction implements QueueAiExpedition
{
    /** The cheapest speed the host accepts (10%), the classic expedition cruise. */
    private const EXPEDITION_SPEED = 1.0;

    /** The shortest expedition, the classic hourly cadence. */
    private const EXPEDITION_HOLDING_HOURS = 1;

    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $planetId, int $galaxy, int $system): AiActionResult
    {
        if (!Planet::query()->whereKey($planetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $planetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $origin = $this->planetServiceFactory->makeForPlayer($player, $planetId, false);
            $fleet = $this->disposableFleet($player, $origin);
            if ($fleet === null) {
                return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                new Coordinate($galaxy, $system, 16),
                PlanetType::Planet,
                ExpeditionMission::getTypeId(),
                $fleet,
                new Resources(),
                self::EXPEDITION_SPEED,
                self::EXPEDITION_HOLDING_HOURS,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * The expedition fleet: one of each role the body owns — the strongest combat
     * hull (survive a pirate), the fastest civil hull (pathfinder), the smallest
     * cargo (carry the find) and a probe — never the whole stock (EXP-002). Roles
     * the body does not own are simply absent, and each hull flies once.
     */
    private function disposableFleet(PlayerService $player, PlanetService $origin): ?UnitCollection
    {
        $planner = app(QueueableExpeditionPlanner::class);

        $cargo = $planner->disposableShip($player, $origin);
        if ($cargo === null) {
            return null;
        }

        $hulls = [$cargo->machine_name => $cargo];

        foreach ([$planner->combatHull($player, $origin), $planner->fastestCivilHull($player, $origin)] as $ship) {
            if ($ship !== null) {
                $hulls[$ship->machine_name] = $ship;
            }
        }

        $probe = ObjectService::getUnitObjectByMachineName(EspionageMission::getRequiredShipMachineNames()[0]);
        if ($origin->getShipUnits()->getAmountByMachineName($probe->machine_name) > 0) {
            $hulls[$probe->machine_name] = $probe;
        }

        $fleet = new UnitCollection();
        foreach ($hulls as $ship) {
            $fleet->addUnit($ship, 1);
        }

        return $fleet;
    }
}
