<?php

namespace Modules\AI\Domain\Perception;

use OGame\Factories\PlayerServiceFactory;

/**
 * Reduces only the AI account's own current state into a transport-safe input.
 *
 * Enemy intelligence enters through explicitly published reports elsewhere;
 * keeping it out here prevents a future policy from accidentally gaining host
 * model access it was never meant to have.
 */
class PlayerObservationService
{
    public function __construct(private PlayerServiceFactory $playerServiceFactory)
    {
    }

    /** @return array{player_id:int, observed_at:int, planets:array<int, array{id:int, resources:array<string, float|int>}>} */
    public function ownedState(int $playerId): array
    {
        $player = $this->playerServiceFactory->make($playerId, true);
        $planets = [];
        foreach ($player->planets->all() as $planet) {
            $planets[] = [
                'id' => $planet->getPlanetId(),
                'resources' => [
                    'metal' => $planet->metal()->get(),
                    'crystal' => $planet->crystal()->get(),
                    'deuterium' => $planet->deuterium()->get(),
                ],
            ];
        }

        return ['player_id' => $player->getId(), 'observed_at' => (int) now()->timestamp, 'planets' => $planets];
    }
}
