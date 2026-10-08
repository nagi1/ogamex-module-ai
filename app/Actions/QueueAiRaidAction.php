<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Perception\ActivityIntelReader;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\FleetUnionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;
use OGame\Services\SettingsService;

/**
 * Module-owned adapter over the host's attack fleet path.
 *
 * The decision and the target are the module's; the mission, its legality and the
 * battle itself are the host's. The raiding fleet is the origin planet's own
 * ships, deducted atomically by the host's mission start.
 */
class QueueAiRaidAction implements QueueAiRaid
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
        private ActivityIntelReader $activityIntelReader,
    ) {
    }

    public function handle(int $playerId, int $originPlanetId, int $targetGalaxy, int $targetSystem, int $targetPosition, int $targetType, ?array $launchUnits = null, int|null $missionType = null): AiActionResult
    {
        if (!Planet::query()->whereKey($originPlanetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $originPlanetId);

            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $origin = $this->planetServiceFactory->makeForPlayer($player, $originPlanetId, false);

            $targetCoordinate = new Coordinate($targetGalaxy, $targetSystem, $targetPosition);

            // Between planning and dispatch the target may have logged in. The
            // activity star is galaxy-visible, so flying into a just-touched
            // target is a recall or a ninja, not a raid (RAID-010) -- unless the
            // neighbour holds a wall: a fleet parked at home can be fleetsaved out
            // of the way and loose resources spent, but a wall and the pile it
            // covers stay where they are, so that haul is still there to take
            // (LIFE_FIGHTS).
            $target = $this->planetServiceFactory->makeForCoordinate($targetCoordinate, false, PlanetType::from($targetType));
            if ($target !== null && $this->activityIntelReader->activityAt($target) && $target->getDefenseUnits()->units === []) {
                return AiActionResult::rejected(AiQueueActionReason::TargetActiveAtDispatch);
            }

            // A moon active while its planet is quiet is the defender moving a
            // fleet on the moon — staging the trap a raid would fly into
            // (NIN-005). Flying into it is a ninja, not a raid.
            $moon = $this->planetServiceFactory->makeMoonForCoordinate($targetCoordinate);
            if ($moon !== null && $target !== null && $this->activityIntelReader->moonOnlyActivity($moon, $target)) {
                return AiActionResult::rejected(AiQueueActionReason::TargetStagingAtDispatch);
            }

            $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            $fleet = $this->launchFleet($origin, $launchUnits);

            // The host refuses a flight the origin cannot pay the fuel for; spending deuterium on
            // mines or ferries between planning and dispatch is how a planned raid ends up here.
            $fuel = (float) $fleetMissions->calculateConsumption($origin, $fleet, $targetCoordinate, 0, 10.0);
            if ($fuel > floor($origin->deuterium()->get())) {
                return AiActionResult::rejected(AiQueueActionReason::SourceShortAtDispatch);
            }
            // The host refuses a flight the fleet's own tanks cannot hold the fuel for ("insufficient
            // storage capacity"); a long route flown by a small subset is how a planned raid ends up here.
            if ($fuel > $fleet->getTotalFuelCapacity($player)) {
                return AiActionResult::rejected(AiQueueActionReason::FuelTankShort);
            }

            $mission = $fleetMissions->createNewFromPlanet(
                $origin,
                $targetCoordinate,
                PlanetType::from($targetType),
                $missionType ?? AttackMission::getTypeId(),
                $fleet,
                new Resources(),
                10,
            );
            $this->allianceCombat($playerId, $mission, $target);

            return AiActionResult::queued($mission->id);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    /**
     * An attack is flown with the alliance (the host's ACS): a mate's union already flying at this target is joined when the
     * fleet arrives inside the host's delay limit, and an attack on an alliance enemy is opened as a union for the mates. A
     * refusal leaves it the attack it already is.
     */
    private function allianceCombat(int $playerId, FleetMission $mission, ?PlanetService $target): void
    {
        if ($mission->mission_type !== AttackMission::getTypeId() || !app(SettingsService::class)->allianceCombatSystemOn()) {
            return;
        }

        $unions = app(FleetUnionService::class);
        $union = FleetUnion::query()
            ->where('galaxy_to', $mission->galaxy_to)
            ->where('system_to', $mission->system_to)
            ->where('position_to', $mission->position_to)
            ->where('planet_type_to', $mission->type_to)
            ->where('user_id', '!=', $playerId)
            ->where('time_arrival', '>', now()->timestamp)
            ->get()
            ->first(static fn (FleetUnion $open): bool => $unions->canPlayerJoinUnion($open, $playerId) && !$open->hasReachedMaxFleets());

        try {
            if ($union !== null && $mission->time_arrival <= $union->time_arrival + $unions->getMaxDelayTime($union)) {
                $unions->joinUnion($union, $mission);

                return;
            }

            $owner = $target?->getPlayer()?->getId();
            if ($owner !== null && isset(app(Enemies::class)->of($playerId)[$owner]) && $this->hasAllianceMates($playerId)) {
                $unions->createUnion($mission);
            }
        } catch (Exception) {
            return;
        }
    }

    private function hasAllianceMates(int $playerId): bool
    {
        $allianceId = User::query()->whereKey($playerId)->value('alliance_id');

        return $allianceId !== null && User::query()->where('alliance_id', $allianceId)->whereKeyNot($playerId)->exists();
    }

    /**
     * The fleet the raid flies: the counter-selected subset, or the whole origin fleet when the
     * intent carried no subset (an older work item). The host still owns what the planet holds.
     *
     * @param array<string, int>|null $launchUnits
     */
    private function launchFleet(PlanetService $origin, ?array $launchUnits): UnitCollection
    {
        if ($launchUnits === null || $launchUnits === []) {
            return $origin->getShipUnits();
        }

        $fleet = new UnitCollection();
        foreach ($launchUnits as $machineName => $amount) {
            if ($amount > 0) {
                $fleet->addUnit(ObjectService::getUnitObjectByMachineName($machineName), $amount);
            }
        }

        return $fleet->units === [] ? $origin->getShipUnits() : $fleet;
    }
}
