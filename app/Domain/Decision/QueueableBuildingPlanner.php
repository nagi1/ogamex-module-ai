<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\User;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\ResearchQueueService;

/**
 * Answers one question about the account's own economy: is there a building or a technology it can
 * legally queue now, and on which planet?
 *
 * Two things want a building. The chain wants the facility a later capability cannot exist without
 * -- an account with no research lab can never research, and one with no shipyard can never own a
 * ship -- and the economy wants the upgrade that repays itself fastest, or the storage that is about
 * to overflow. The plan runs in two passes across the account's planets: first a warehouse that is
 * about to overflow anywhere (it stops that planet producing, so it outranks every routine step on
 * every other planet), then the routine economy planet by planet -- the energy a planet needs before
 * it throttles, the chain's facilities, then the fastest-paying mine. The cross-planet storage pass
 * is what keeps a full warehouse on one colony from waiting behind a routine mine on the homeworld.
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
        private ResearchQueueService $researchQueueService,
    ) {
    }

    public function plan(int $playerId): QueueableBuilding|QueueableResearch|null
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

        // Refresh every planet's live balance once: the candidate pass reads stored amounts and
        // energy, and a balance read stale is exactly the balance the queue later cancels on. The
        // refresh stays in memory -- the observation path must not write.
        $planets = $this->playerServiceFactory->make($playerId, true)->planets->all();
        foreach ($planets as $planet) {
            $planet->updateResources(false);
            $planet->updateResourceProductionStats(false);
            $planet->updateResourceStorageStats(false);
        }

        // A warehouse about to overflow stops that planet producing wherever it sits, so it outranks
        // every routine step on every other planet. Without this pass the first planet always won:
        // it always has a queueable step, and the newest colonies filled to the cap while the
        // homeworld kept buying.
        foreach ($planets as $planet) {
            $step = $this->firstQueueable($planet, $profile, $this->economyUpgrades->storage($planet, $profile));
            if ($step !== null) {
                return $step;
            }
        }

        // The routine economy, planet by planet in the account's own order: the energy a planet
        // needs before it throttles, the chain's facilities, then the fastest-paying mine.
        foreach ($planets as $planet) {
            $step = $this->firstQueueable($planet, $profile, [
                ...$this->energyCapacity->pending($planet),
                ...$this->facilityChain->pending($planet),
                ...$this->economyUpgrades->production($planet, $profile),
            ]);
            if ($step !== null) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The first candidate this planet can actually queue, or null when none of them is legal.
     *
     * @param list<BuildCandidate> $candidates
     */
    private function firstQueueable(PlanetService $planet, AiProfile $profile, array $candidates): QueueableBuilding|QueueableResearch|null
    {
        foreach ($candidates as $candidate) {
            // Which queue takes a step is the host's object type, not this module's opinion: the
            // chain hands over prerequisites, and a technology among them is research.
            if (ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research) {
                $research = $this->queueableResearch($planet, $candidate);
                if ($research === null) {
                    continue;
                }

                return $research;
            }

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

    /**
     * The same question for a technology, asked the way the host's research page asks it.
     *
     * Research is account-wide on the host's screen while its queue and its requirements are per
     * planet, so the planet that answers is the planet whose laboratory carries it. Nothing here
     * names a technology: the price, the requirement graph and the queue are all the host's.
     */
    private function queueableResearch(PlanetService $planet, BuildCandidate $candidate): ?QueueableResearch
    {
        $machineName = ObjectService::getObjectById($candidate->buildingId)->machine_name;

        $queueable = !$this->researchQueueService->retrieveQueue($planet)->isQueueFull()
            && ObjectService::objectRequirementsMetWithQueue($machineName, ($planet->getPlayer()?->getResearchLevel($machineName) ?? 0) + 1, $planet)
            && $planet->hasResources(ObjectService::getObjectPrice($machineName, $planet));

        if (!$queueable) {
            return null;
        }

        return app()->makeWith(QueueableResearch::class, [
            'planetId' => $planet->getPlanetId(),
            'researchId' => $candidate->buildingId,
            'reason' => $candidate->reason,
        ]);
    }
}
