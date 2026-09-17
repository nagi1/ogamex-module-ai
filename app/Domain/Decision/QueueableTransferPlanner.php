<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\TransportMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

/**
 * Whether this account should ferry resources from one of its bodies to another, and which.
 *
 * A colony that cannot pay for its next level waits on the homeworld's surplus; a player looks at
 * what the colony wants to build next, subtracts what is already on it and already flying to it, and
 * ships the difference from the planet that can spare it without starving itself (E4/X1). The
 * shortfall is netted against in-flight transports so one hole is never funded twice, the source must
 * keep its reserve (SP5), and a shipment below the ordinary minimum is not worth the fleet's time.
 *
 * Nothing here names a game object. What a planet wants next is the same host-quoted step the
 * economy planner asks -- storage overflow, energy, the facility chain, then the best mine -- and its
 * price is the host's price, so an extension that adds a build or a resource changes the ferry with
 * no module edit.
 */
class QueueableTransferPlanner
{
    /** r4fek's documented floor: combined metal and crystal below this is not worth a shipment. */
    private const MINIMUM_SHIPMENT = 50_000;

    /** A planet at this fraction of its storage is about to overflow and is swept (E9). */
    private const SURPLUS_RATIO = 0.8;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private EconomyUpgrades $economyUpgrades,
        private EnergyCapacity $energyCapacity,
        private FacilityChain $facilityChain,
        private ReserveFloor $reserveFloor,
    ) {
    }

    public function plan(int $playerId): ?QueueableTransfer
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
        if (count($planets) < 2) {
            return null;
        }

        foreach ($planets as $planet) {
            $planet->updateResources(false);
            $planet->updateResourceProductionStats(false);
            $planet->updateResourceStorageStats(false);
        }

        foreach ($planets as $target) {
            $need = $this->need($target, $profile, $playerId);
            if ($need === null || !$this->worthShipping($need)) {
                continue;
            }

            $source = $this->source($planets, $target, $need);
            if ($source === null) {
                continue;
            }

            return app()->makeWith(QueueableTransfer::class, [
                'sourcePlanetId' => $source->getPlanetId(),
                'targetPlanetId' => $target->getPlanetId(),
                'metal' => (int) round($need->metal->get()),
                'crystal' => (int) round($need->crystal->get()),
                'deuterium' => (int) round($need->deuterium->get()),
            ]);
        }

        return $this->surplus($planets);
    }

    /**
     * The reverse of the need-driven ferry: a planet about to overflow ships its
     * above-floor surplus to the best-developed body, so the mines do not stall
     * (E9/X2). A moon keeps its deuterium for fleet jumps; a planet sweeps all
     * three resources.
     *
     * @param array<PlanetService> $planets
     */
    private function surplus(array $planets): ?QueueableTransfer
    {
        $drop = $this->dropBody($planets);
        if ($drop === null) {
            return null;
        }

        foreach ($planets as $source) {
            if ($source->getPlanetId() === $drop->getPlanetId() || !$this->nearCap($source)) {
                continue;
            }

            $floor = $this->reserveFloor->floor($source, ReserveFloor::ECONOMY_HOURS);
            $shipment = $this->aboveFloor($source, $floor, $source->getPlanetType() === PlanetType::Moon);
            if (!$this->worthShipping($shipment)) {
                continue;
            }

            return app()->makeWith(QueueableTransfer::class, [
                'sourcePlanetId' => $source->getPlanetId(),
                'targetPlanetId' => $drop->getPlanetId(),
                'metal' => (int) round($shipment->metal->get()),
                'crystal' => (int) round($shipment->crystal->get()),
                'deuterium' => (int) round($shipment->deuterium->get()),
            ]);
        }

        return null;
    }

    /**
     * The most developed own planet, by the host's building count: the drop the
     * surplus consolidates onto. Moons are never the drop.
     *
     * @param array<PlanetService> $planets
     */
    private function dropBody(array $planets): ?PlanetService
    {
        $best = null;
        foreach ($planets as $planet) {
            if ($planet->getPlanetType() === PlanetType::Moon) {
                continue;
            }

            if ($best === null || $planet->getBuildingCount() > $best->getBuildingCount()) {
                $best = $planet;
            }
        }

        return $best;
    }

    /** Whether either stored resource is at or past the near-cap threshold. */
    private function nearCap(PlanetService $planet): bool
    {
        return $planet->metal()->get() >= self::SURPLUS_RATIO * $planet->metalStorage()->get()
            || $planet->crystal()->get() >= self::SURPLUS_RATIO * $planet->crystalStorage()->get();
    }

    /** What a body ships above its reserve floor; a moon keeps its deuterium. */
    private function aboveFloor(PlanetService $source, Resources $floor, bool $keepDeuterium): Resources
    {
        return new Resources(
            max(0.0, $source->metal()->get() - $floor->metal->get()),
            max(0.0, $source->crystal()->get() - $floor->crystal->get()),
            $keepDeuterium ? 0.0 : max(0.0, $source->deuterium()->get() - $floor->deuterium->get()),
        );
    }

    /**
     * What this planet is short for its next level: its price minus what is already there and already
     * flying to it, floored at zero so a planet that has enough ships nothing.
     */
    private function need(PlanetService $target, AiProfile $profile, int $playerId): ?Resources
    {
        $price = $this->nextStepPrice($target, $profile);
        if ($price === null) {
            return null;
        }

        $inFlight = $this->inFlightTo($target, $playerId);

        return new Resources(
            max(0.0, $price->metal->get() - $target->metal()->get() - $inFlight->metal->get()),
            max(0.0, $price->crystal->get() - $target->crystal()->get() - $inFlight->crystal->get()),
            max(0.0, $price->deuterium->get() - $target->deuterium()->get() - $inFlight->deuterium->get()),
        );
    }

    /**
     * The next level this planet wants, in the same order the economy planner takes: storage that is
     * about to overflow, energy before the throttle, the chain's facilities, then the best mine.
     */
    private function nextStepPrice(PlanetService $planet, AiProfile $profile): ?Resources
    {
        $candidates = [
            ...$this->economyUpgrades->storage($planet, $profile),
            ...$this->energyCapacity->pending($planet),
            ...$this->facilityChain->pending($planet),
            ...$this->economyUpgrades->production($planet, $profile),
        ];

        $first = $candidates[0] ?? null;
        if ($first === null) {
            return null;
        }

        return ObjectService::getObjectPrice(ObjectService::getObjectById($first->buildingId)->machine_name, $planet);
    }

    /** The account's own transports already on the way to this body, netted off before the shortfall. */
    private function inFlightTo(PlanetService $target, int $playerId): Resources
    {
        $missions = FleetMission::query()
            ->where('user_id', $playerId)
            ->where('planet_id_to', $target->getPlanetId())
            ->where('mission_type', TransportMission::getTypeId())
            ->where('processed', 0)
            ->where('canceled', 0)
            ->where('time_arrival', '>=', now()->timestamp)
            ->get(['metal', 'crystal', 'deuterium']);

        return new Resources(
            (float) $missions->sum('metal'),
            (float) $missions->sum('crystal'),
            (float) $missions->sum('deuterium'),
        );
    }

    /**
     * The first other body that can spare the shipment and still keep its own reserve.
     *
     * @param array<PlanetService> $planets
     */
    private function source(array $planets, PlanetService $target, Resources $need): ?PlanetService
    {
        foreach ($planets as $source) {
            if ($source->getPlanetId() === $target->getPlanetId()) {
                continue;
            }

            $floor = $this->reserveFloor->floor($source, ReserveFloor::ECONOMY_HOURS);
            // A resource the shipment does not carry keeps no floor: the source only reserves what it
            // actually spends, so a metal-and-crystal ferry is not blocked by a deuterium reserve.
            // ponytail: deuterium fuel is not reserved here (it is distance- and fleet-dependent), so
            // a source that can spare the cargo but not the fuel is rejected by the host at dispatch —
            // a recoverable failure, upgrade path is quoting fuel in the planner.
            $required = new Resources(
                $need->metal->get() > 0 ? $need->metal->get() + $floor->metal->get() : 0,
                $need->crystal->get() > 0 ? $need->crystal->get() + $floor->crystal->get() : 0,
                $need->deuterium->get() > 0 ? $need->deuterium->get() + $floor->deuterium->get() : 0,
            );

            if ($source->hasResources($required)) {
                return $source;
            }
        }

        return null;
    }

    private function worthShipping(Resources $need): bool
    {
        return $need->metal->get() + $need->crystal->get() >= self::MINIMUM_SHIPMENT;
    }
}
