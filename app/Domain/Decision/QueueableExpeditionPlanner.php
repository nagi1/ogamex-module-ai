<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\ColonisationMission;
use OGame\GameMissions\EspionageMission;
use OGame\GameObjects\Models\UnitObject;
use OGame\Models\User;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Answers whether this account can dispatch an expedition now.
 *
 * An expedition is the host's slot-16 mission; the module only adds the
 * feasibility the host itself would refuse — Astrophysics researched, a free
 * expedition slot, and a disposable cargo ship on some own body. The fleet is
 * never the account's whole stock (EXP-001): the dispatch sends one small
 * civil ship.
 */
class QueueableExpeditionPlanner
{
    /** Slot 16 is the only coordinate the host's expedition mission accepts. */
    private const EXPEDITION_POSITION = 16;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
    ) {
    }

    public function plan(int $playerId): ?QueueableExpedition
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);

        // The host requires Astrophysics before any expedition, and refuses a
        // dispatch when every slot is already out.
        if ($player->getResearchLevel('astrophysics') <= 0) {
            return null;
        }
        if ($player->getExpeditionSlotsInUse() >= $player->getExpeditionSlotsMax()) {
            return null;
        }

        $origin = $this->origin($player);
        if ($origin === null) {
            return null;
        }

        $coordinates = $origin->getPlanetCoordinates();

        return app()->makeWith(QueueableExpedition::class, [
            'planetId' => $origin->getPlanetId(),
            'galaxy' => (int) $coordinates->galaxy,
            'system' => (int) $coordinates->system,
            'position' => self::EXPEDITION_POSITION,
        ]);
    }

    /**
     * The disposable cargo ship this body can send, or null when it has none.
     *
     * The expedition fleet is a civil ship with cargo space — the smallest one,
     * so it is the cheapest to lose to the host's black-hole outcome (EXP-001).
     * The probe and the colony ship are excluded by the host's own mission
     * vocabulary; military hulls are the fleet the account depends on.
     * ponytail: the fleet is fixed at one hull; a persona band that varies the
     * count by skill band is the upgrade path once the executor is observed.
     */
    public function disposableShip(PlayerService $player, PlanetService $planet): ?UnitObject
    {
        $civilMachines = array_map(
            static fn (UnitObject $ship): string => $ship->machine_name,
            ObjectService::getCivilShipObjects(),
        );
        $probe = EspionageMission::getRequiredShipMachineNames()[0];
        $colonyShip = ColonisationMission::getRequiredShipMachineNames()[0];

        $smallest = null;
        foreach ($planet->getShipUnits()->units as $entry) {
            $machine = $entry->unitObject->machine_name;
            if (!in_array($machine, $civilMachines, true) || $machine === $probe || $machine === $colonyShip) {
                continue;
            }

            $capacity = $entry->unitObject->properties->capacity->calculate($player)->totalValue;
            if ($capacity <= 0) {
                continue;
            }

            if ($smallest === null || $capacity < $smallest['capacity']) {
                $smallest = ['ship' => $entry->unitObject, 'capacity' => $capacity];
            }
        }

        return $smallest['ship'] ?? null;
    }

    /**
     * The first own body carrying a disposable cargo ship.
     */
    private function origin(PlayerService $player): ?PlanetService
    {
        foreach ($player->planets->all() as $planet) {
            if ($this->disposableShip($player, $planet) !== null) {
                return $planet;
            }
        }

        return null;
    }
}
