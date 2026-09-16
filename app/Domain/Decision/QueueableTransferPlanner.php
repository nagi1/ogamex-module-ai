<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\TransportMission;
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

        return null;
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
