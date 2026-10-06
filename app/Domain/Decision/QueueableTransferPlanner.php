<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Actions\QueueAiTransferAction;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AllyGiftGuard;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\TransportMission;
use OGame\Models\BattleReport;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

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
    /**
     * r4fek's documented floor: combined metal and crystal below this is not worth a shipment.
     * Shared with the dispatch adapter, which re-applies it to the clamped amount rather than
     * flying a fleet for what is left of a spent surplus.
     */
    public const MINIMUM_SHIPMENT = 50_000;

    /** A planet at this fraction of its storage is about to overflow and is swept (E9). */
    private const SURPLUS_RATIO = 0.8;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private EconomyUpgrades $economyUpgrades,
        private EnergyCapacity $energyCapacity,
        private FacilityChain $facilityChain,
        private DefenseNeedEvaluator $defenseNeed,
        private ReserveFloor $reserveFloor,
        private QueueAiTransferAction $transferAction,
        private AllyGiftGuard $giftGuard,
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

        // A player does not load the same hulls twice: a ferry still waiting to fly holds the cargo
        // ships, so a second one offered now fails at dispatch with no transport fleet.
        if ($this->transferWaiting($playerId)) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();
        if (count($planets) < 2) {
            return null;
        }

        // A source the gate just refused is not a source this login offers again: a refused dispatch is
        // not a mission, so nothing else recorded the empty tank or the missing hull.
        $refusedOrigins = app(RecentRefusals::class)->refusedOrigins($playerId, AiWorkKind::Transfer);

        foreach ($planets as $planet) {
            $planet->updateResources(false);
            $planet->updateResourceProductionStats(false);
            $planet->updateResourceStorageStats(false);
        }

        foreach ($planets as $target) {
            $wallNeed = $this->wallNeed($target, $planets, $playerId);
            $need = $wallNeed ?? $this->need($target, $profile, $playerId);
            if ($need === null || ($wallNeed === null && !$this->worthShipping($need))) {
                continue;
            }

            $source = $this->source($planets, $target, $need, $player, $refusedOrigins);
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

        return $this->surplus($planets, $player, $refusedOrigins) ?? $this->allyGift($planets, $player, $playerId, $refusedOrigins);
    }

    /**
     * The ferry an inbound asks for: the pile a threatened body cannot keep leaves for a sibling the
     * same attack is not aimed at (D6). A player whose planet is about to be hit loads the hulls he is
     * keeping at home and moves what the raider would otherwise carry off; the fleet that is worth the
     * trip is already leaving, and this is the stock it leaves behind.
     *
     * The shipment is the same above-floor sweep the surplus ferry flies, so the body keeps its own
     * economy running while the pile goes, and the hold, the fuel and the target legality stay the
     * dispatch adapter's questions: a pile bigger than the holds is loaded as far as the holds go.
     *
     * Null when there is nothing above the body's reserve, no sibling out of the attack's reach, or no
     * hull on the body to carry it -- the account keeps what it cannot move.
     *
     * @param list<int> $threatenedPlanetIds the own bodies this attack is aimed at, none of which may receive
     */
    public function evacuationPlan(int $playerId, int $planetId, array $threatenedPlanetIds): ?QueueableTransfer
    {
        $player = $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();

        // A body the gate just refused a dispatch from cannot launch, so the pile stays where it is and
        // offering the move again only repeats the refusal.
        if (isset(app(RecentRefusals::class)->refusedOrigins($playerId, AiWorkKind::Transfer)[$planetId])) {
            return null;
        }

        $source = null;
        foreach ($planets as $planet) {
            if ($planet->getPlanetId() === $planetId) {
                $source = $planet;
            }
        }

        if ($source === null) {
            return null;
        }

        $source->updateResources(false);
        if (!$this->hasCargo($source, $player)) {
            return null;
        }

        $shipment = $this->aboveFloor($source, $this->reserveFloor->floor($source, ReserveFloor::ECONOMY_HOURS), false);
        if (!$this->worthShipping($shipment)) {
            return null;
        }

        foreach ($planets as $target) {
            if ($target->getPlanetId() === $planetId || in_array($target->getPlanetId(), $threatenedPlanetIds, true)) {
                continue;
            }

            if (!$this->canPayFuel($source, $target, $shipment, $player)) {
                continue;
            }

            return app()->makeWith(QueueableTransfer::class, [
                'sourcePlanetId' => $source->getPlanetId(),
                'targetPlanetId' => $target->getPlanetId(),
                'metal' => (int) round($shipment->metal->get()),
                'crystal' => (int) round($shipment->crystal->get()),
                'deuterium' => (int) round($shipment->deuterium->get()),
            ]);
        }

        return null;
    }

    /** How long after seeing an ally attacked a gift is still offered. */
    private const ALLY_GIFT_WINDOW_HOURS = 6;

    /**
     * A tenth of what the richest body holds, sent to an alliance co-member just seen attacked
     * (IMPL-71): a player helps an ally rebuild after a raid. Nothing leaves unless AllyGiftGuard
     * passes the exact shipment, so the planner never offers what the adapter would refuse.
     *
     * @param array<PlanetService> $planets
     * @param array<int, true> $refusedOrigins own bodies the gate just refused a dispatch from
     */
    private function allyGift(array $planets, PlayerService $player, int $playerId, array $refusedOrigins): ?QueueableTransfer
    {
        $reports = AiObservation::query()
            ->where('player_id', $playerId)
            ->where('kind', AiObservationKind::AllyUnderAttack)
            ->where('observed_at', '>=', now()->subHours(self::ALLY_GIFT_WINDOW_HOURS))
            ->orderByDesc('observed_at')
            ->pluck('source_id');

        foreach ($reports as $reportId) {
            $allyId = BattleReport::query()->whereKey($reportId)->value('planet_user_id');
            $targetId = $allyId === null ? null : Planet::query()->where('user_id', $allyId)->orderBy('id')->value('id');
            if ($targetId === null) {
                continue;
            }

            foreach ($planets as $source) {
                if (isset($refusedOrigins[$source->getPlanetId()])) {
                    continue;
                }

                $floor = $this->reserveFloor->floor($source, ReserveFloor::ECONOMY_HOURS);
                $share = AllyGiftGuard::MAX_STOCK_SHARE;
                $gift = new Resources(
                    floor(min($source->metal()->get() * $share, max(0.0, $source->metal()->get() - $floor->metal->get()))),
                    floor(min($source->crystal()->get() * $share, max(0.0, $source->crystal()->get() - $floor->crystal->get()))),
                    0.0,
                );
                if ($gift->sum() <= 0 || !$this->hasCargo($source, $player)) {
                    continue;
                }
                if ($this->giftGuard->refusal(
                    $playerId,
                    (int) $targetId,
                    (int) $gift->metal->get(),
                    (int) $gift->crystal->get(),
                    0,
                    $source->metal()->get(),
                    $source->crystal()->get(),
                    $source->deuterium()->get(),
                ) !== null) {
                    continue 2;
                }
                $targetPlanet = $this->planetServiceFactory->make((int) $targetId, true);
                if ($targetPlanet === null || !$this->canPayFuel($source, $targetPlanet, $gift, $player)) {
                    continue;
                }

                return app()->makeWith(QueueableTransfer::class, [
                    'sourcePlanetId' => $source->getPlanetId(),
                    'targetPlanetId' => (int) $targetId,
                    'metal' => (int) $gift->metal->get(),
                    'crystal' => (int) $gift->crystal->get(),
                    'deuterium' => 0,
                ]);
            }
        }

        return null;
    }

    /**
     * A ferry that has already loaded the hulls keeps them until it flies, so a second one would
     * fail at dispatch with no transport fleet. Only an intent that names its shipment is on the
     * pad: the re-plan arm runs while the account's own intent carries no payload to read, and
     * counting that one would refuse every re-plan of the intent it is executing.
     */
    private function transferWaiting(int $playerId): bool
    {
        return AiWorkItem::query()
            ->where('player_id', $playerId)
            ->where('kind', AiWorkKind::Transfer)
            ->whereIn('state', [AiWorkState::Pending, AiWorkState::Retry, AiWorkState::Leased])
            ->get(['payload'])
            ->contains(static fn (AiWorkItem $item): bool => isset($item->payload['source_planet_id']));
    }

    /**
     * The reverse of the need-driven ferry: a planet about to overflow ships its
     * above-floor surplus to the best-developed body, so the mines do not stall
     * (E9/X2). A moon keeps its deuterium for fleet jumps; a planet sweeps all
     * three resources.
     *
     * @param array<PlanetService> $planets
     * @param array<int, true> $refusedOrigins own bodies the gate just refused a dispatch from
     */
    private function surplus(array $planets, PlayerService $player, array $refusedOrigins): ?QueueableTransfer
    {
        $drop = $this->dropBody($planets);
        if ($drop === null) {
            return null;
        }

        foreach ($planets as $source) {
            if ($source->getPlanetId() === $drop->getPlanetId() || isset($refusedOrigins[$source->getPlanetId()]) || !$this->nearCap($source)) {
                continue;
            }

            $floor = $this->reserveFloor->floor($source, ReserveFloor::ECONOMY_HOURS);
            $shipment = $this->aboveFloor($source, $floor, $source->getPlanetType() === PlanetType::Moon);
            if (!$this->worthShipping($shipment) || !$this->hasCargo($source, $player) || !$this->canPayFuel($source, $drop, $shipment, $player)) {
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
     * What a bare planet beside a walled sibling is short for the facility its wall waits on, or null.
     *
     * A player ships the missing few hundred deuterium to the colony that cannot raise its yard: the
     * shipment floor protects the fleet's time, but a planet stuck below one cheap step stays naked
     * for good without the ferry (measured live 3 Oct 2026: 22k metal, 116 deuterium, no robotics).
     *
     * @param array<PlanetService> $planets
     */
    private function wallNeed(PlanetService $target, array $planets, int $playerId): ?Resources
    {
        if ($this->defenseNeed->standingUnits($target) > 0) {
            return null;
        }

        $siblingWalled = array_filter($planets, fn (PlanetService $planet): bool => $this->defenseNeed->standingUnits($planet) > 0);
        if ($siblingWalled === []) {
            return null;
        }

        // The first facility the planet cannot pay for is what keeps it bare; one it can pay for it builds itself.
        $inFlight = $this->inFlightTo($target, $playerId);
        foreach ($this->facilityChain->wallPending($target) as $step) {
            $price = ObjectService::getObjectPrice(ObjectService::getObjectById($step->buildingId)->machine_name, $target);
            $need = new Resources(
                max(0.0, $price->metal->get() - $target->metal()->get() - $inFlight->metal->get()),
                max(0.0, $price->crystal->get() - $target->crystal()->get() - $inFlight->crystal->get()),
                max(0.0, $price->deuterium->get() - $target->deuterium()->get() - $inFlight->deuterium->get()),
            );
            if ($need->sum() > 0) {
                return $need;
            }
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

        // The ferry funds the step the planet cannot reach on its own, so a candidate whose whole
        // price is below the shipment floor -- a cheap research the planet pays for from an hour of
        // income -- is not the shortfall a player ships resources for; the next step down the same
        // list is.
        foreach ($candidates as $candidate) {
            $price = ObjectService::getObjectPrice(ObjectService::getObjectById($candidate->buildingId)->machine_name, $planet);
            if ($this->worthShipping($price)) {
                return $price;
            }
        }

        return null;
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
     * @param array<int, true> $refusedOrigins own bodies the gate just refused a dispatch from
     */
    private function source(array $planets, PlanetService $target, Resources $need, PlayerService $player, array $refusedOrigins): ?PlanetService
    {
        foreach ($planets as $source) {
            if ($source->getPlanetId() === $target->getPlanetId() || isset($refusedOrigins[$source->getPlanetId()])) {
                continue;
            }

            $floor = $this->reserveFloor->floor($source, ReserveFloor::ECONOMY_HOURS);
            // A resource the shipment does not carry keeps no floor: the source only reserves what it
            // actually spends, so a metal-and-crystal ferry is not blocked by a deuterium reserve.
            $required = new Resources(
                $need->metal->get() > 0 ? $need->metal->get() + $floor->metal->get() : 0,
                $need->crystal->get() > 0 ? $need->crystal->get() + $floor->crystal->get() : 0,
                $need->deuterium->get() > 0 ? $need->deuterium->get() + $floor->deuterium->get() : 0,
            );

            if ($source->hasResources($required) && $this->hasCargo($source, $player) && $this->canPayFuel($source, $target, $need, $player)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * A ferry needs a hull with a hold: a source with no cargo ships can never fly, so the planner
     * does not publish that transfer. `ponytail:` asks "any cargo", not "enough" — an under-supplied
     * source still refuses at dispatch (`NoTransportFleet`), bounded by the account building cargo;
     * the upgrade is comparing `getTotalCargoCapacity` to the shipment here.
     */
    private function hasCargo(PlanetService $source, PlayerService $player): bool
    {
        return $source->getShipUnits()->getTotalCargoCapacity($player) > 0;
    }

    /** The flight burns the source's own deuterium on top of the cargo; a source with empty tanks cannot launch. */
    private function canPayFuel(PlanetService $source, PlanetService $target, Resources $need, PlayerService $player): bool
    {
        $fuel = $this->transferAction->flightFuel($player, $source, $target, $need);

        return $fuel !== null && $fuel <= floor($source->deuterium()->get());
    }

    private function worthShipping(Resources $need): bool
    {
        return $need->metal->get() + $need->crystal->get() >= self::MINIMUM_SHIPMENT;
    }
}
