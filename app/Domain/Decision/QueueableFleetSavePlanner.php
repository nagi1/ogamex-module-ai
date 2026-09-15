<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\DeploymentMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Answers whether this account can move its fleet off a threatened planet now.
 *
 * A fleetsave is a deployment between the account's own planets: the fleet
 * leaves the planet an inbound hostile can reach and parks on another body the
 * account owns. Both the fleet and the destination are read from the host — the
 * origin is whichever planet carries ships, the destination is any other planet
 * — so the module names neither a ship nor a coordinate.
 */
class QueueableFleetSavePlanner
{
    /** Below this offline gap a save is routine cadence, not an absence (FS-001). */
    private const PROACTIVE_SAVE_MIN_ABSENCE_MINUTES = 120;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
    ) {
    }

    public function plan(int $playerId): ?QueueableFleetSave
    {
        $profile = $this->profile($playerId);
        if ($profile === null || !User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);

        return $this->saveFor($player, $player->planets->all(), $profile->archetype);
    }

    /**
     * The save a player takes before logging off for a real absence, rather
     * than the reactive one an inbound hostile forces (V6). The absence must
     * clear the routine's own inter-session gap and the fleet left behind must
     * clear the persona's exposure band; either failing, the account simply
     * carries on with its ordinary session.
     */
    public function proactivePlan(int $playerId, int $absenceMinutes): ?QueueableFleetSave
    {
        if ($absenceMinutes < self::PROACTIVE_SAVE_MIN_ABSENCE_MINUTES) {
            return null;
        }

        $profile = $this->profile($playerId);
        if ($profile === null || !User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();
        $origin = $this->origin($planets);
        if ($origin === null || $this->fleetValue($origin) < $this->exposureBand($profile->archetype)) {
            return null;
        }

        return $this->saveFor($player, $planets, $profile->archetype);
    }

    private function profile(int $playerId): ?AiProfile
    {
        return AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
    }

    /**
     * @param array<int, PlanetService> $planets
     */
    private function saveFor(PlayerService $player, array $planets, AiArchetype $archetype): ?QueueableFleetSave
    {
        $origin = $this->origin($planets);
        if ($origin === null) {
            return null;
        }

        $ranked = $this->rankedDestinations($player, $planets, $origin);
        if ($ranked === []) {
            return null;
        }

        return app()->makeWith(QueueableFleetSave::class, [
            'originPlanetId' => $origin->getPlanetId(),
            'destinationPlanetId' => $ranked[0]->getPlanetId(),
            'missionType' => DeploymentMission::getTypeId(),
            'shadowDestinationPlanetId' => $this->shadowDestinationPlanetId($player, $origin, $ranked, $archetype),
        ]);
    }

    /**
     * The second own body a large fleet is split to, or 0 when it is not (V8).
     * A split needs two own bodies, a free second slot, both hull roles present,
     * and a fleet at least twice what the persona bothers to save, so each wave
     * is still worth the trip (FS-009).
     *
     * @param list<PlanetService> $ranked
     */
    private function shadowDestinationPlanetId(PlayerService $player, PlanetService $origin, array $ranked, AiArchetype $archetype): int
    {
        if (count($ranked) < 2) {
            return 0;
        }

        if ($player->getFleetSlotsMax() - $player->getFleetSlotsInUse() < 2) {
            return 0;
        }

        if (!$this->hasBothRoles($origin)) {
            return 0;
        }

        if ($this->fleetValue($origin) < 2 * $this->exposureBand($archetype)) {
            return 0;
        }

        return $ranked[1]->getPlanetId();
    }

    /**
     * A shadow split moves combat and civil hulls apart, so a fleet with only
     * one role has nothing to split (FS-009).
     */
    private function hasBothRoles(PlanetService $planet): bool
    {
        $military = array_map(static fn ($object): string => $object->machine_name, ObjectService::getMilitaryShipObjects());
        $civil = array_map(static fn ($object): string => $object->machine_name, ObjectService::getCivilShipObjects());

        $hasMilitary = false;
        $hasCivil = false;
        foreach (array_keys($planet->getShipUnits()->toArray()) as $machineName) {
            $hasMilitary = $hasMilitary || in_array($machineName, $military, true);
            $hasCivil = $hasCivil || in_array($machineName, $civil, true);
        }

        return $hasMilitary && $hasCivil;
    }

    /**
     * The resources spent on the ships parked here, in the host's raw-price
     * unit. Defence is deliberately excluded: a save moves ships, never a
     * planet's built defences, so defence weight must not push a turtled
     * account into saving a lone cargo (FS-001).
     */
    private function fleetValue(PlanetService $planet): float
    {
        $value = 0.0;
        foreach ($planet->getShipUnits()->toArray() as $machineName => $amount) {
            $value += ObjectService::getObjectRawPrice($machineName)->sum() * $amount;
        }

        return $value;
    }

    /**
     * The minimum fleet value a persona bothers to save (FS-001). A fleeter
     * saves anything real; a trader, miner, turtle or casual player only a
     * fleet that is worth the trip. Persona taste over host data, never a
     * gate-1 object list.
     */
    private function exposureBand(AiArchetype $archetype): int
    {
        return match ($archetype) {
            AiArchetype::Fleeter => 5_000,
            AiArchetype::Trader => 25_000,
            AiArchetype::Miner, AiArchetype::Turtle, AiArchetype::Casual => 50_000,
        };
    }

    /**
     * The first planet carrying a movable fleet.
     *
     * @param array<int, PlanetService> $planets
     */
    private function origin(array $planets): ?PlanetService
    {
        foreach ($planets as $planet) {
            if ($planet->getShipUnits()->units !== []) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * Own destinations ranked by safety: moons first (the phalanx cannot see
     * them), then by distance from the origin (a save that flies further is
     * harder to phalanx-time) (CRASH-006, FS-005).
     *
     * @param array<int, PlanetService> $planets
     * @return list<PlanetService>
     */
    private function rankedDestinations(PlayerService $player, array $planets, PlanetService $origin): array
    {
        $destinations = array_values(array_filter(
            $planets,
            static fn (PlanetService $planet): bool => $planet->getPlanetId() !== $origin->getPlanetId(),
        ));

        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        usort(
            $destinations,
            function (PlanetService $left, PlanetService $right) use ($fleetMissions, $origin): int {
                $moonPreference = $this->isMoon($right) <=> $this->isMoon($left);
                if ($moonPreference !== 0) {
                    return $moonPreference;
                }

                return $fleetMissions->calculateFleetMissionDistance($origin, $right->getPlanetCoordinates())
                    <=> $fleetMissions->calculateFleetMissionDistance($origin, $left->getPlanetCoordinates());
            },
        );

        return $destinations;
    }

    private function isMoon(PlanetService $planet): bool
    {
        return $planet->getPlanetType() === PlanetType::Moon;
    }

    /**
     * The parked save this account can bring home, or null when nothing is out.
     *
     * The host never returns a deployment on its own, so the recall is the other
     * half of the save: the account's own in-flight deployment between two of
     * its bodies. The ownership check the host's cancel path lacks is the
     * `user_id` filter here.
     */
    public function recallPlan(int $playerId): ?QueueableRecall
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        $deployment = FleetMission::query()
            ->where('user_id', $playerId)
            ->where('mission_type', DeploymentMission::getTypeId())
            ->where('canceled', 0)
            ->where('processed', 0)
            ->where('time_arrival', '>=', now()->timestamp)
            ->whereColumn('planet_id_from', '!=', 'planet_id_to')
            ->orderBy('time_arrival')
            ->first();

        if ($deployment === null) {
            return null;
        }

        return app()->makeWith(QueueableRecall::class, [
            'planetId' => (int) $deployment->planet_id_from,
            'missionId' => (int) $deployment->id,
        ]);
    }
}
