<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiRecycle;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\RecycleMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\DebrisField;
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
 * Module-owned adapter over the host's harvest (recycle) fleet path.
 *
 * The decision, the field and the harvest-hull count are the module's; the
 * mission, its legality and the harvest itself are the host's. The hull is the
 * one the host's own RecycleMission requires for the target slot.
 */
class QueueAiRecycleAction implements QueueAiRecycle
{
    /** The cheapest speed the host accepts (10%), a slow harvest run. */
    private const RECYCLE_SPEED = 10.0;

    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $planetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType): AiActionResult
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
            $fleet = $this->harvestFleet($player, $origin, $targetGalaxy, $targetSystem, $targetPosition);
            if ($fleet === null) {
                return AiActionResult::rejected(AiQueueActionReason::NoDisposableFleet);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                new Coordinate($targetGalaxy, $targetSystem, $targetPosition),
                PlanetType::from($targetType),
                RecycleMission::getTypeId(),
                $fleet,
                new Resources(),
                self::RECYCLE_SPEED,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * Enough harvest hulls to carry the field, capped by what the body holds.
     *
     * The hull is the one the host's RecycleMission requires for the slot and its
     * capacity is the host's own figure, so a mod that changes either changes the
     * count with no module edit. At least one hull flies for a field still worth
     * a trip.
     */
    private function harvestFleet(PlayerService $player, PlanetService $origin, int $galaxy, int $system, int $position): ?UnitCollection
    {
        $ship = ObjectService::getShipObjectByMachineName(RecycleMission::getHarvesterMachineNameForPosition($position));
        $available = $origin->getShipUnits()->getAmountByMachineName($ship->machine_name);
        if ($available <= 0) {
            return null;
        }

        $capacity = $ship->properties->capacity->calculate($player)->totalValue;
        $needed = (int) ceil($this->fieldMass($galaxy, $system, $position) / max(1, $capacity));
        $count = max(1, min($available, $needed));

        $fleet = new UnitCollection();
        $fleet->addUnit($ship, $count);

        return $fleet;
    }

    /** The field's total mass at dispatch time, re-derived, never trusted from the decision. */
    private function fieldMass(int $galaxy, int $system, int $position): float
    {
        $field = DebrisField::query()
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('planet', $position)
            ->first();

        return $field === null ? 0.0 : (float) $field->metal + (float) $field->crystal + (float) $field->deuterium;
    }
}
