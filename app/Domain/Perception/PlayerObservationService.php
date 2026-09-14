<?php

namespace Modules\AI\Domain\Perception;

use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Lifecycle\AccountStateResolver;
use Modules\AI\Enums\AiAccountState;
use Modules\AI\Enums\AiCapability;
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
    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private QueueableBuildingPlanner $queueableBuildingPlanner,
        private AccountStateResolver $accountStateResolver,
    ) {
    }

    /** @return array{player_id:int, observed_at:int, account_state:string, planets:array<int, array{id:int, resources:array<string, float|int>}>, available_actions:array<string, bool>} */
    public function ownedState(int $playerId): array
    {
        $state = $this->accountStateResolver->resolve($playerId);

        return [
            'player_id' => $playerId,
            'observed_at' => (int) now()->timestamp,
            'account_state' => $state->value,
            'planets' => $state === AiAccountState::Final ? [] : $this->planets($playerId),
            // A suspended account is not playing, and the host is the authority
            // on that state: a banned or vacationing account is offered nothing
            // rather than a capability it cannot act on, so its session records
            // that it did nothing instead of recording a decision the host
            // would refuse. An account with no planets, and one the host no
            // longer has, are offered nothing for the same reason.
            'available_actions' => $state === AiAccountState::Active ? $this->availableActions($playerId) : [],
        ];
    }

    /** @return array<int, array{id:int, resources:array<string, float|int>}> */
    private function planets(int $playerId): array
    {
        $planets = [];
        foreach ($this->playerServiceFactory->make($playerId, true)->planets->all() as $planet) {
            $planets[] = [
                'id' => $planet->getPlanetId(),
                'resources' => [
                    'metal' => $planet->metal()->get(),
                    'crystal' => $planet->crystal()->get(),
                    'deuterium' => $planet->deuterium()->get(),
                ],
            ];
        }

        return $planets;
    }

    /**
     * Only a capability the module can actually carry out is published.
     *
     * Publishing one it cannot is how the population came to decide without ever acting: a trace
     * would claim an action while the host was never touched, which reads as a quiet population
     * rather than as the gap it is.
     *
     * @return array<string, bool>
     */
    private function availableActions(int $playerId): array
    {
        return [AiCapability::Build->value => $this->queueableBuildingPlanner->plan($playerId) !== null];
    }
}
