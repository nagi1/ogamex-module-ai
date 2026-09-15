<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter over the host's attack fleet path.
 *
 * The decision and the target are the module's; the mission, its legality and the
 * battle itself are the host's. The raiding fleet is the origin planet's own
 * ships, deducted atomically by the host's mission start.
 */
class QueueAiRaidAction implements QueueAiRaid
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $originPlanetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType): AiActionResult
    {
        if (!Planet::query()->whereKey($originPlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $originPlanetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $origin = $this->planetServiceFactory->makeForPlayer($player, $originPlanetId, false);

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                new Coordinate($targetGalaxy, $targetSystem, $targetPosition),
                PlanetType::from($targetType),
                AttackMission::getTypeId(),
                $origin->getShipUnits(),
                new Resources(),
                10,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
