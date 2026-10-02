<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Persona\PersonaTaste;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\DeploymentMission;
use OGame\GameMissions\EspionageMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\JumpGateService;
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
        private QueueableRecyclePlanner $queueableRecyclePlanner,
        private JumpGateService $jumpGate,
    ) {
    }

    public function plan(int $playerId, ?PlayerService $player = null): ?QueueableFleetSave
    {
        $profile = $this->profile($playerId);
        if ($profile === null || !User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player ??= $this->playerServiceFactory->make($playerId, true);
        $planets = $player->planets->all();

        // The reactive save (an inbound hostile) is not a matter of taste: a save under
        // attack always wins, so it uses the base band. Aggression only moves the proactive
        // save below, where the account chooses how much fleet it is willing to risk.
        return $this->saveFor($player, $planets, $profile->archetype, 0.5, null, $this->threatenedPlanetIds($player));
    }

    /**
     * The own planets a hostile fleet is inbound to, from the host's real fleet picture:
     * a mission aimed at one of the account's planets, sent by someone else and still
     * unprocessed. An inbound hostile is owned by the attacker, so the account's own
     * missions can never answer what a reactive save has to move (V6). Which mission
     * types are hostile is the host's own reading — the perception's, not a module list.
     *
     * @return array<int, true>
     */
    private function threatenedPlanetIds(PlayerService $player): array
    {
        $planetIds = [];
        foreach ($player->planets->all() as $planet) {
            $planetIds[] = $planet->getPlanetId();
        }

        if ($planetIds === []) {
            return [];
        }

        $threatened = [];
        foreach (FleetMission::query()
            ->whereIn('planet_id_to', $planetIds)
            ->where('user_id', '!=', $player->getId())
            ->where('processed', 0)
            ->where('canceled', 0)
            ->where('mission_type', '!=', EspionageMission::getTypeId())
            ->get(['planet_id_to']) as $mission) {
            $threatened[(int) $mission->planet_id_to] = true;
        }

        return $threatened;
    }

    /**
     * The save a player takes before logging off for a real absence, rather
     * than the reactive one an inbound hostile forces (V6). The absence must
     * clear the routine's own inter-session gap; the fleet-value gate lives in
     * saveFor, shared with the reactive plan.
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

        return $this->saveFor($player, $player->planets->all(), $profile->archetype, $this->aggression($profile), $absenceMinutes);
    }

    private function profile(int $playerId): ?AiProfile
    {
        return AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
    }

    private function aggression(AiProfile $profile): float
    {
        return PersonaTaste::fromSeed((int) $profile->random_seed, app(RandomSource::class))->aggression;
    }

    /**
     * @param array<int, PlanetService> $planets
     * @param array<int, true> $preferredPlanetIds
     */
    private function saveFor(PlayerService $player, array $planets, AiArchetype $archetype, float $aggression = 0.5, ?int $absenceMinutes = null, array $preferredPlanetIds = []): ?QueueableFleetSave
    {
        $origin = $this->origin($planets, $preferredPlanetIds);
        if ($origin === null || $this->fleetValue($origin) < $this->exposureBand($archetype, $aggression)) {
            return null;
        }

        // A save moves ships; a planet holding only solar satellites has
        // nothing to move and must not plan a flight over an empty fleet.
        if (MovableFleet::of($player, $origin->getShipUnits())->units === []) {
            return null;
        }

        // A jump gate moves the whole fleet between two owned moons with no flight time, so
        // it beats any flight in exposure: when it is available it is the save (RV-009).
        $jump = $this->jumpGateTarget($player, $origin);
        if ($jump !== null) {
            return app()->makeWith(QueueableFleetSave::class, [
                'originPlanetId' => $origin->getPlanetId(),
                'destinationPlanetId' => $jump->getPlanetId(),
                'missionType' => DeploymentMission::getTypeId(),
                'jumpGatePlanetId' => $jump->getPlanetId(),
            ]);
        }

        $ranked = $this->rankedDestinations($player, $planets, $origin);
        if ($ranked === []) {
            return $this->harvestSaveFallback($player, $origin);
        }

        $destination = $ranked[0];
        $speed = $absenceMinutes === null
            ? 1.0
            : $this->saveSpeed($player, $origin, $destination, $absenceMinutes);

        return app()->makeWith(QueueableFleetSave::class, [
            'originPlanetId' => $origin->getPlanetId(),
            'destinationPlanetId' => $destination->getPlanetId(),
            'missionType' => DeploymentMission::getTypeId(),
            'shadowDestinationPlanetId' => $this->shadowDestinationPlanetId($player, $origin, $ranked, $archetype, $aggression),
            'speed' => $speed,
        ]);
    }

    /**
     * The fastest save speed whose outbound flight still lands at least the
     * absence out (FS-012): the fleet stays away the whole absence and is home
     * again soon after. The slowest speed stays the floor when no speed can
     * reach the absence.
     */
    private function saveSpeed(PlayerService $player, PlanetService $origin, PlanetService $destination, int $absenceMinutes): float
    {
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $absenceSeconds = $absenceMinutes * 60;
        $units = MovableFleet::of($player, $origin->getShipUnits());

        for ($speed = 10; $speed >= 1; $speed--) {
            $duration = $fleetMissions->calculateFleetMissionDuration($origin, $destination->getPlanetCoordinates(), $units, null, (float) $speed);
            if ($duration >= $absenceSeconds) {
                return (float) $speed;
            }
        }

        return 1.0;
    }

    /**
     * The single-planet fallback (FS-011): with no other own body to deploy to,
     * park the fleet on a host debris field via a recycle mission. The field and
     * the recycler hull are the recycle planner's own answer; the fallback only
     * retargets it as a full-fleet save when that planner's origin is this same
     * body, so it never moves a different planet's fleet. ponytail: the recycle
     * mission returns the fleet once it arrives, so a long absence is not fully
     * covered — the speed/distance sweep is the upgrade path.
     */
    private function harvestSaveFallback(PlayerService $player, PlanetService $origin): ?QueueableFleetSave
    {
        $recycle = $this->queueableRecyclePlanner->plan($player->getId());
        if ($recycle === null || $recycle->planetId !== $origin->getPlanetId()) {
            return null;
        }

        return app()->makeWith(QueueableFleetSave::class, [
            'originPlanetId' => $origin->getPlanetId(),
            'destinationPlanetId' => 0,
            'missionType' => $recycle->missionType,
            'harvestGalaxy' => $recycle->targetGalaxy,
            'harvestSystem' => $recycle->targetSystem,
            'harvestPosition' => $recycle->targetPosition,
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
    private function shadowDestinationPlanetId(PlayerService $player, PlanetService $origin, array $ranked, AiArchetype $archetype, float $aggression = 0.5): int
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

        if ($this->fleetValue($origin) < 2 * $this->exposureBand($archetype, $aggression)) {
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
     * fleet that is worth the trip. Aggression is risk appetite: a bold account
     * tolerates a larger fleet unsaved, a cautious one saves smaller fleets.
     * Persona taste over host data, never a gate-1 object list.
     */
    private function exposureBand(AiArchetype $archetype, float $aggression = 0.5): int
    {
        $base = match ($archetype) {
            AiArchetype::Fleeter => 5_000,
            AiArchetype::Trader => 25_000,
            AiArchetype::Miner, AiArchetype::Turtle, AiArchetype::Casual => 50_000,
        };

        // Neutral at aggression 0.5: the base band is what a middling account saves.
        // A cautious account (0) saves half as much fleet; a bold one (1) half again as much.
        return (int) round($base * (0.5 + $aggression));
    }

    /**
     * The first planet carrying a movable fleet, preferring the planets a hostile
     * is inbound to: a reactive save has to move the fleet the attack is aimed
     * at, not whichever own body happens to come first in the host's list.
     *
     * @param array<int, PlanetService> $planets
     * @param array<int, true> $preferredPlanetIds
     */
    private function origin(array $planets, array $preferredPlanetIds = []): ?PlanetService
    {
        foreach ($planets as $planet) {
            if (isset($preferredPlanetIds[$planet->getPlanetId()]) && $planet->getShipUnits()->units !== []) {
                return $planet;
            }
        }

        foreach ($planets as $planet) {
            if ($planet->getShipUnits()->units !== []) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * Own destinations ranked by safety: a body a hostile is already inbound to
     * is never a destination (FS-010), the origin's own same-coordinate moon is
     * the phalanx-blind hop and ranks first (FS-005), then moons (the phalanx
     * cannot see them), then by distance from the origin (a save that flies
     * further is harder to phalanx-time) (CRASH-006).
     *
     * @param array<int, PlanetService> $planets
     * @return list<PlanetService>
     */
    private function rankedDestinations(PlayerService $player, array $planets, PlanetService $origin): array
    {
        $unsafe = $this->unsafeDestinations($player);

        $destinations = array_values(array_filter(
            $planets,
            static fn (PlanetService $planet): bool => $planet->getPlanetId() !== $origin->getPlanetId()
                && !isset($unsafe[$planet->getPlanetId()]),
        ));

        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        usort(
            $destinations,
            function (PlanetService $left, PlanetService $right) use ($fleetMissions, $origin): int {
                $sameCoordinateMoonPreference = $this->isSameCoordinateMoon($origin, $right)
                    <=> $this->isSameCoordinateMoon($origin, $left);
                if ($sameCoordinateMoonPreference !== 0) {
                    return $sameCoordinateMoonPreference;
                }

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

    /**
     * The jump-gate save: the origin must be a moon with a gate, the origin must not be on
     * cooldown, and at least one other gated moon must be free of both cooldown and an inbound
     * hostile (RV-009). The host's own eligibility answers the rest.
     */
    private function jumpGateTarget(PlayerService $player, PlanetService $origin): ?PlanetService
    {
        if ($origin->getPlanetType() !== PlanetType::Moon || $origin->getObjectLevel('jump_gate') < 1) {
            return null;
        }

        if ($this->jumpGate->isOnCooldown($origin)) {
            return null;
        }

        $unsafe = $this->unsafeDestinations($player);

        foreach ($this->jumpGate->getEligibleTargets($player, $origin) as $target) {
            if (!isset($unsafe[$target->getPlanetId()])) {
                return $target;
            }
        }

        return null;
    }

    /**
     * The first own body a hostile fleet is already inbound to. Parking the save on one
     * of them is worse than holding, so they are not destinations (FS-010). The
     * same active-mission source the inbound picture already reads, so this is
     * the one authority for "under attack".
     *
     * @return array<int, true>
     */
    private function unsafeDestinations(PlayerService $player): array
    {
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $unsafe = [];
        foreach ($fleetMissions->getActiveFleetMissionsForCurrentPlayer() as $mission) {
            if ($mission->user_id !== $player->getId()) {
                $unsafe[(int) $mission->planet_id_to] = true;
            }
        }

        return $unsafe;
    }

    /**
     * A moon at the origin's own coordinate: the planet↔moon hop a phalanx
     * cannot observe (FS-005). The single safest save, ranked ahead of any
     * other moon.
     */
    private function isSameCoordinateMoon(PlanetService $origin, PlanetService $planet): bool
    {
        if ($planet->getPlanetType() !== PlanetType::Moon) {
            return false;
        }

        $originCoordinate = $origin->getPlanetCoordinates();
        $targetCoordinate = $planet->getPlanetCoordinates();

        return $originCoordinate->galaxy === $targetCoordinate->galaxy
            && $originCoordinate->system === $targetCoordinate->system
            && $originCoordinate->position === $targetCoordinate->position;
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

        // The fleet parks half its outbound flight, then returns, with a
        // deterministic per-account jitter so a cohort does not all recall on
        // the same tick (FS-007).
        $flightSeconds = max(0, (int) $deployment->time_arrival - (int) $deployment->time_departure);
        $half = (int) round($flightSeconds / 2);
        $jitter = (($profile->random_seed % 21) - 10) / 100.0;

        return app()->makeWith(QueueableRecall::class, [
            'planetId' => (int) $deployment->planet_id_from,
            'missionId' => (int) $deployment->id,
            'recallAt' => (int) $deployment->time_arrival + $half + (int) round($half * $jitter),
        ]);
    }
}
