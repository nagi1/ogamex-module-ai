<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiColony;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\ColonisationMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter over the host's colonisation fleet path.
 *
 * The decision is the module's; the mission, its legality and the slot check are
 * the host's. The colony ship is deducted atomically by the host's own mission
 * start, so this adapter only refuses what the host would refuse anyway and
 * reports whether a mission row actually appeared.
 */
class QueueAiColonyAction implements QueueAiColony
{
    private const COLONY_SHIP = 'colony_ship';

    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $planetId, int $galaxy, int $system, int $position): AiActionResult
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

            $planet = $this->planetServiceFactory->makeForPlayer($player, $planetId, false);
            $units = new UnitCollection();
            $units->addUnit(ObjectService::getUnitObjectByMachineName(self::COLONY_SHIP), 1);

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $planet,
                new Coordinate($galaxy, $system, $position),
                PlanetType::Planet,
                ColonisationMission::getTypeId(),
                $units,
                new Resources(),
                10,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
