<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiRelocation;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Services\BuildingQueueService;
use OGame\Services\DarkMatterService;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetMoveService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\ResearchQueueService;
use OGame\Services\SettingsService;
use OGame\Services\UnitQueueService;

/**
 * Module-owned adapter over the host's planet relocation: the same checks the relocation page runs
 * (empty target, no pending move, no cooldown, dark matter on hand, nothing queued or in flight on the
 * planet) and then the host's own scheduling. The dark matter is taken by the host when the countdown ends.
 */
class QueueAiRelocationAction implements QueueAiRelocation
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
        private PlanetMoveService $planetMoveService,
        private DarkMatterService $darkMatterService,
        private SettingsService $settingsService,
        private BuildingQueueService $buildingQueueService,
        private ResearchQueueService $researchQueueService,
        private UnitQueueService $unitQueueService,
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
            $target = new Coordinate($galaxy, $system, $position);

            if ($this->planetServiceFactory->makePlanetForCoordinate($target, false) !== null
                || $this->planetMoveService->getActiveMoveForPlanet($planet) !== null
                || $this->planetMoveService->getCooldownSecondsForPlanet($planet) > 0
                || !$this->darkMatterService->canAfford($player->getUser(), (int) $this->settingsService->get('planet_relocation_cost', 240000))) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            if ($this->planetMoveService->getBlockingReasons($planet, $this->buildingQueueService, $this->researchQueueService, $this->unitQueueService, $fleetMissions) !== []) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }

            $move = $this->planetMoveService->scheduleMoveForPlanet($planet, $target, $this->settingsService);

            return AiActionResult::queued((int) $move->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
