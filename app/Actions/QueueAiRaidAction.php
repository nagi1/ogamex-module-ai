<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Domain\Perception\ActivityIntelReader;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
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
        private ActivityIntelReader $activityIntelReader,
    ) {
    }

    public function handle(int $playerId, int $originPlanetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType, ?array $launchUnits = null): AiActionResult
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

            $targetCoordinate = new Coordinate($targetGalaxy, $targetSystem, $targetPosition);

            // Between planning and dispatch the target may have logged in. The
            // activity star is galaxy-visible, so flying into a just-touched
            // target is a recall or a ninja, not a raid (RAID-010).
            $target = $this->planetServiceFactory->makeForCoordinate($targetCoordinate, false, PlanetType::from($targetType));
            if ($target !== null && $this->activityIntelReader->activityAt($target)) {
                return AiActionResult::rejected(AiQueueActionReason::TargetActiveAtDispatch);
            }

            // A moon active while its planet is quiet is the defender moving a
            // fleet on the moon — staging the trap a raid would fly into
            // (NIN-005). Flying into it is a ninja, not a raid.
            $moon = $this->planetServiceFactory->makeMoonForCoordinate($targetCoordinate);
            if ($moon !== null && $target !== null && $this->activityIntelReader->moonOnlyActivity($moon, $target)) {
                return AiActionResult::rejected(AiQueueActionReason::TargetStagingAtDispatch);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                $targetCoordinate,
                PlanetType::from($targetType),
                AttackMission::getTypeId(),
                $this->launchFleet($origin, $launchUnits),
                new Resources(),
                10,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * The fleet the raid flies: the counter-selected subset, or the whole origin fleet when the
     * intent carried no subset (an older work item). The host still owns what the planet holds.
     *
     * @param array<string, int>|null $launchUnits
     */
    private function launchFleet(PlanetService $origin, ?array $launchUnits): UnitCollection
    {
        if ($launchUnits === null || $launchUnits === []) {
            return $origin->getShipUnits();
        }

        $fleet = new UnitCollection();
        foreach ($launchUnits as $machineName => $amount) {
            if ($amount > 0) {
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName($machineName), $amount);
            }
        }

        return $fleet->units === [] ? $origin->getShipUnits() : $fleet;
    }
}
