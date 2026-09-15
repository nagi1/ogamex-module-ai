<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiTransfer;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\TransportMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\PlayerService;

/**
 * Module-owned adapter over the host's transport mission.
 *
 * The target legality, the cargo-capacity check and the atomic resource debit are the host's; the
 * module adds the ship selection -- just enough of the cargo hulls the source already owns to carry
 * the shipment, never the combat fleet a player would not risk on a ferry run.
 */
class QueueAiTransferAction implements QueueAiTransfer
{
    /** 100%: a ferry is wanted at the target, not parked in space. */
    private const TRANSPORT_SPEED = 10.0;

    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $sourcePlanetId, int $targetPlanetId, int $metal, int $crystal, int $deuterium): AiActionResult
    {
        if (!Planet::query()->whereKey($sourcePlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }
        if (!Planet::query()->whereKey($targetPlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $sourcePlanetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $source = $this->planetServiceFactory->makeForPlayer($player, $sourcePlanetId, false);
            $target = $this->planetServiceFactory->makeForPlayer($player, $targetPlanetId, false);
            $shipment = new Resources($metal, $crystal, $deuterium);

            $fleet = $this->transportFleet($player, $source, $shipment);
            if ($fleet === null) {
                return AiActionResult::rejected(AiQueueActionReason::NoTransportFleet);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $mission = $fleetMissions->createNewFromPlanet(
                $source,
                $target->getPlanetCoordinates(),
                $target->getPlanetType(),
                TransportMission::getTypeId(),
                $fleet,
                $shipment,
                self::TRANSPORT_SPEED,
            );

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * Just enough of the cargo hulls the source already owns to carry the shipment, or null when the
     * source's fleet cannot carry it. Ships without cargo capacity are never taken, so a ferry run
     * never moves the combat fleet.
     */
    private function transportFleet(PlayerService $player, PlanetService $source, Resources $shipment): ?UnitCollection
    {
        $fleet = new UnitCollection();
        $remaining = $shipment->sum();
        if ($remaining <= 0) {
            return null;
        }

        foreach ($source->getShipUnits()->units as $entry) {
            $capacity = $entry->unitObject->properties->capacity->calculate($player)->totalValue;
            if ($capacity <= 0) {
                continue;
            }

            $amount = min((int) ceil($remaining / $capacity), $entry->amount);
            if ($amount <= 0) {
                continue;
            }

            $fleet->addUnit($entry->unitObject, $amount);
            $remaining -= $amount * $capacity;
            if ($remaining <= 0) {
                return $fleet;
            }
        }

        return null;
    }
}
