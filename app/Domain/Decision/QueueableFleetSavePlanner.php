<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\DeploymentMission;
use OGame\Models\User;
use OGame\Services\PlanetService;

/**
 * Answers whether this account can move its fleet off a threatened planet now.
 *
 * A fleetsave is a deployment between the account's own planets: the fleet
 * leaves the planet an inbound hostile can reach and parks on another body the
 * account owns. Both the fleet and the destination are read from the host — the
 * origin is whichever planet carries ships, the destination is any other planet
 * — so the module names neither a ship nor a coordinate.
 */
class QueueableFleetSavePlanner
{
    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
    ) {
    }

    public function plan(int $playerId): ?QueueableFleetSave
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();

        $origin = $this->origin($planets);
        if ($origin === null) {
            return null;
        }

        foreach ($planets as $destination) {
            if ($destination->getPlanetId() === $origin->getPlanetId()) {
                continue;
            }

            return app()->makeWith(QueueableFleetSave::class, [
                'originPlanetId' => $origin->getPlanetId(),
                'destinationPlanetId' => $destination->getPlanetId(),
                'missionType' => DeploymentMission::getTypeId(),
            ]);
        }

        return null;
    }

    /**
     * The first planet carrying a movable fleet.
     *
     * @param array<int, PlanetService> $planets
     */
    private function origin(array $planets): ?PlanetService
    {
        foreach ($planets as $planet) {
            if ($planet->getShipUnits()->units !== []) {
                return $planet;
            }
        }

        return null;
    }
}
