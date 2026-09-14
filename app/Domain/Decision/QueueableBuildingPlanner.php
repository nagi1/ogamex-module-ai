<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

/**
 * Answers one question about the account's own economy: is there a building it can legally queue
 * now, and on which planet?
 *
 * Two things want a building. The chain wants the facility a later capability cannot exist without
 * -- an account with no research lab can never research, and one with no shipyard can never own a
 * ship -- and the economy wants the upgrade that repays itself fastest, or the storage that is about
 * to overflow. The chain is asked first because an economy that never reaches a facility produces an
 * account that grows resources and nothing else; the economy's own ranking follows, so once the
 * facilities stand the account is back to its own arithmetic.
 *
 * Ahead of both sits a planet that cannot cover the energy its own buildings draw: the host throttles
 * everything it produces, and no player keeps mining their way through a deficit.
 *
 * Both are only suggestions. Every gate is the host's own -- planet type, free queue space,
 * requirements met against what is built *and* queued, and a price the planet can pay, which is the
 * same set the building page shows a human -- so the module never restates an OGame rule. A target
 * the host rejects is not an error but a fall-through: the planner tries the next one, which is what
 * keeps a single impossible favourite from costing the account its whole build capability.
 *
 * Affordability is a real gate rather than a nicety: `BuildingQueueService::start()` cancels a queue
 * item it cannot pay for, so publishing `build` while short of resources spends a queue slot and
 * reports nothing at all.
 */
class QueueableBuildingPlanner
{
    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private FacilityChain $facilityChain,
        private EnergyCapacity $energyCapacity,
        private EconomyUpgrades $economyUpgrades,
        private BuildingQueueService $buildingQueueService,
    ) {
    }

    public function plan(int $playerId): ?QueueableBuilding
    {
        // An account the module does not manage has no policy to apply, so it gets no capability.
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        // The module owns profile rows; the host owns accounts. A profile can outlive its account or
        // be written by a fixture, and loading an account the host does not have throws -- so the
        // precondition is asked here rather than discovered in the host service.
        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        foreach ($this->playerServiceFactory->make($playerId, true)->planets->all() as $planet) {
            // Resources are read live: the stored amounts only advance when something touches the
            // planet, and a balance read stale is exactly the balance the queue later cancels on.
            // The refresh stays in memory -- the observation path must not write. The energy balance
            // and the storage capacity are stored columns the host recomputes when it touches a
            // planet, so they are recomputed here the same way, in memory, or a planet that has just
            // grown would be judged on the balance and the warehouse it had before its last mine --
            // or its last storage -- finished.
            $planet->updateResources(false);
            $planet->updateResourceProductionStats(false);
            $planet->updateResourceStorageStats(false);

            foreach ([...$this->energyCapacity->pending($planet), ...$this->facilityChain->pending($planet), ...$this->economyUpgrades->pending($planet, $profile)] as $candidate) {
                $planetId = $this->queueablePlanetId($planet, $candidate);
                if ($planetId === null) {
                    continue;
                }

                return app()->makeWith(QueueableBuilding::class, [
                    'planetId' => $planetId,
                    'buildingId' => $candidate->buildingId,
                    'reason' => $candidate->reason,
                ]);
            }
        }

        return null;
    }

    private function queueablePlanetId(PlanetService $planet, BuildCandidate $candidate): ?int
    {
        $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;

        // The host's own gates for a legal queue request, asked in the order its building page asks
        // them: planet type, free queue space, met requirements and a balance it can pay. They read
        // as one predicate because one planet either accepts the building or does not; the rejected
        // reason is the host's to report when an intent is actually attempted.
        $queueable = ObjectService::objectValidPlanetType($machineName, $planet)
            && !$this->buildingQueueService->retrieveQueue($planet)->isQueueFull()
            && ObjectService::objectRequirementsMetWithQueue($machineName, $planet->getObjectLevel($machineName) + 1, $planet)
            && $planet->hasResources(ObjectService::getObjectPrice($machineName, $planet));

        if (!$queueable) {
            return null;
        }

        return $planet->getPlanetId();
    }
}
