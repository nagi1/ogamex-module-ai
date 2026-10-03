<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiMissile;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\MissileMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter that launches the same missile mission the galaxy overlay launches: the host's own
 * possibility check (range, own planet, destroyed moon, missiles held), then the mission row and the
 * missiles leaving the silo. The overlay does this inside a controller, so the row is written here the way
 * it writes it.
 */
class QueueAiMissileAction implements QueueAiMissile
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $originPlanetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType, int $missiles): AiActionResult
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
            $held = $origin->getObjectAmount('interplanetary_missile');
            $missiles = min($missiles, $held);
            if ($missiles < 1) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }

            $targetCoordinate = new Coordinate($targetGalaxy, $targetSystem, $targetPosition);
            $type = PlanetType::from($targetType);
            $status = app(MissileMission::class)->isMissionPossible($origin, $targetCoordinate, $type, new UnitCollection());
            if (!$status->possible) {
                return AiActionResult::rejected($status->error !== '' ? $status->error : AiQueueActionReason::NothingQueueable);
            }

            $target = $this->planetServiceFactory->makeForCoordinate($targetCoordinate, true, $type);
            if ($target === null) {
                return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
            }

            $distance = abs($origin->getPlanetCoordinates()->system - $targetSystem);
            // The overlay's own formula: it does not scale the flight by universe speed either.
            $flightTime = 30 + 60 * $distance;
            $now = now();

            $mission = new FleetMission();
            $mission->user_id = $playerId;
            $mission->planet_id_from = $originPlanetId;
            $mission->planet_id_to = $target->getPlanetId();
            $mission->galaxy_from = $origin->getPlanetCoordinates()->galaxy;
            $mission->system_from = $origin->getPlanetCoordinates()->system;
            $mission->position_from = $origin->getPlanetCoordinates()->position;
            $mission->galaxy_to = $targetGalaxy;
            $mission->system_to = $targetSystem;
            $mission->position_to = $targetPosition;
            $mission->type_from = $origin->getPlanetType()->value;
            $mission->type_to = $targetType;
            $mission->mission_type = MissileMission::getTypeId();
            $mission->time_departure = (int) $now->timestamp;
            $mission->time_arrival = (int) $now->timestamp + $flightTime;
            $mission->time_arrival_ms = (int) $now->valueOf() + ($flightTime * 1000);
            $mission->canceled = 0;
            $mission->processed = 0;
            $mission->interplanetary_missile = $missiles;
            $mission->target_priority = 0;
            $mission->save();

            $origin->removeUnit('interplanetary_missile', $missiles);
            $origin->save();

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
