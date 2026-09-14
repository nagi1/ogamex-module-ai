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
 * Every gate is the host's own -- planet type, requirements including the queue, affordability and
 * free queue space, which is what the building page shows a human -- so the module never restates
 * an OGame rule. Only *which* building the account wants is module policy, and it comes from
 * `BuildFirstBuilding`, the same chooser the executor runs later. That is what stops a published
 * capability and the queued intent from drifting apart.
 *
 * Affordability is a real gate rather than a nicety: `BuildingQueueService::start()` cancels a queue
 * item it cannot pay for, so publishing `build` while short of resources spends a queue slot and
 * reports nothing at all.
 */
class QueueableBuildingPlanner
{
    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private BuildFirstBuilding $buildFirstBuilding,
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

        $buildingId = (int) $this->buildFirstBuilding->choose($profile)['building_id'];
        $machineName = ObjectService::getObjectById($buildingId)->machine_name;

        foreach ($this->playerServiceFactory->make($playerId, true)->planets->all() as $planet) {
            $planetId = $this->queueablePlanetId($planet, $machineName);
            if ($planetId === null) {
                continue;
            }

            return app()->makeWith(QueueableBuilding::class, ['planetId' => $planetId, 'buildingId' => $buildingId]);
        }

        return null;
    }

    private function queueablePlanetId(PlanetService $planet, string $machineName): ?int
    {
        // Resources are read live: the stored amounts only advance when something touches the
        // planet, and a balance read stale is exactly the balance the queue later cancels on. The
        // refresh stays in memory -- the observation path must not write.
        $planet->updateResources(false);

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
