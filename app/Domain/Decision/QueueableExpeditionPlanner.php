<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\ColonisationMission;
use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\ExpeditionMission;
use OGame\GameObjects\Models\UnitObject;
use OGame\Models\FleetMission;
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

    /** The window over which a system's outgoing expedition load is counted for rotation. */
    private const ROTATION_WINDOW_HOURS = 24;

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
     * The strongest combat hull this body owns, by the host's attack value: the
     * escort that survives a pirate (EXP-002).
     */
    public function combatHull(PlayerService $player, PlanetService $planet): ?UnitObject
    {
        return $this->bestOwned($player, $planet, ObjectService::getMilitaryShipObjects(), 'attack');
    }

    /**
     * The fastest civil hull this body owns, by the host's speed value: the
     * pathfinder that shortens the trip (EXP-002). The probe and the colony ship
     * are excluded by the host's own mission vocabulary.
     */
    public function fastestCivilHull(PlayerService $player, PlanetService $planet): ?UnitObject
    {
        return $this->bestOwned(
            $player,
            $planet,
            ObjectService::getCivilShipObjects(),
            'speed',
            [...EspionageMission::getRequiredShipMachineNames(), ...ColonisationMission::getRequiredShipMachineNames()],
        );
    }

    /**
     * The best-owned hull for a role, by the host's own stat. Only hulls the
     * body actually holds are candidates; the role is never a machine name.
     *
     * @param array<UnitObject> $objects
     * @param 'attack'|'speed' $property
     * @param list<string> $exclude
     */
    private function bestOwned(PlayerService $player, PlanetService $planet, array $objects, string $property, array $exclude = []): ?UnitObject
    {
        $owned = $planet->getShipUnits()->toArray();

        $best = null;
        $bestValue = -1.0;
        foreach ($objects as $object) {
            if (in_array($object->machine_name, $exclude, true) || ($owned[$object->machine_name] ?? 0) <= 0) {
                continue;
            }

            $value = $object->properties->{$property}->calculate($player)->totalValue;
            if ($value > $bestValue) {
                $best = $object;
                $bestValue = $value;
            }
        }

        return $best;
    }

    /**
     * The own body whose system has sent the fewest recent expeditions, so the
     * account spreads expeditions across its systems instead of hammering one
     * (EXP-003). The rotation falls out of the count; no hard threshold is named.
     */
    private function origin(PlayerService $player): ?PlanetService
    {
        $recent = $this->recentExpeditionsBySystem($player->getId());

        $best = null;
        foreach ($player->planets->all() as $planet) {
            if ($this->disposableShip($player, $planet) === null) {
                continue;
            }

            $coordinates = $planet->getPlanetCoordinates();
            $count = $recent["{$coordinates->galaxy}:{$coordinates->system}"] ?? 0;

            if ($best === null || $count < $best['count']) {
                $best = ['planet' => $planet, 'count' => $count];
            }
        }

        return $best['planet'] ?? null;
    }

    /**
     * @return array<string, int> system key => outgoing expeditions in the rotation window
     */
    private function recentExpeditionsBySystem(int $playerId): array
    {
        $counts = [];
        foreach (FleetMission::query()
            ->where('user_id', $playerId)
            ->where('mission_type', ExpeditionMission::getTypeId())
            ->where('time_departure', '>=', now()->subHours(self::ROTATION_WINDOW_HOURS)->timestamp)
            ->get(['galaxy_from', 'system_from']) as $mission) {
            $key = "{$mission->galaxy_from}:{$mission->system_from}";
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
