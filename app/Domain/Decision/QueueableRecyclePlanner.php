<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\RecycleMission;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Answers whether this account can harvest a debris field now, and which one.
 *
 * Recycling is the closing half of the raid and expedition loop: the debris a
 * battle leaves is income a player sends recyclers for. The field, its mass and
 * the harvest hull are all host-read; the module only adds the feasibility — a
 * field above a minimum mass that is not already being harvested, and an own
 * body that carries the hull the host's recycle mission requires for that slot.
 */
class QueueableRecyclePlanner
{
    /** Bounded: at most this many candidate fields are inspected per decision. */
    private const MAX_CANDIDATES = 20;

    /** A field worth less than this metal-equivalent is not worth the trip. */
    private const MIN_FIELD_MASS = 10_000;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function plan(int $playerId): ?QueueableRecycle
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);

        $covered = $this->coveredCoordinates($playerId) + $this->openRecycleIntentCoordinates($playerId);

        // ponytail: scan the 20 largest fields and take the closest worth having; a
        // distance cap on the scan is the upgrade path once accounts spread fleets
        // across galaxies.
        $fields = DebrisField::query()
            ->orderByDesc('metal')
            ->orderByDesc('crystal')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $best = null;

        foreach ($fields as $field) {
            $coordinateKey = "{$field->galaxy}:{$field->system}:{$field->planet}";
            if (isset($covered[$coordinateKey])) {
                continue;
            }

            $mass = (float) $field->metal + (float) $field->crystal + (float) $field->deuterium;
            if ($mass < self::MIN_FIELD_MASS) {
                continue;
            }

            $shipName = RecycleMission::getHarvesterMachineNameForPosition((int) $field->planet);
            $origin = $this->origin($player, $shipName);
            if ($origin === null) {
                continue;
            }

            $distance = $fleetMissions->calculateFleetMissionDistance(
                $origin,
                new Coordinate((int) $field->galaxy, (int) $field->system, (int) $field->planet),
            );

            if ($best === null || $distance < $best['distance']) {
                $best = ['field' => $field, 'origin' => $origin, 'distance' => $distance];
            }
        }

        if ($best === null) {
            return null;
        }

        return app()->makeWith(QueueableRecycle::class, [
            'planetId' => $best['origin']->getPlanetId(),
            'targetGalaxy' => (int) $best['field']->galaxy,
            'targetSystem' => (int) $best['field']->system,
            'targetPosition' => (int) $best['field']->planet,
            'targetType' => PlanetType::DebrisField->value,
            'missionType' => RecycleMission::getTypeId(),
        ]);
    }

    /**
     * The first own body carrying the harvest hull. ponytail: first-body, not
     * closest-to-field; the closest-body scan is the upgrade path.
     */
    private function origin(PlayerService $player, string $shipName): ?PlanetService
    {
        foreach ($player->planets->all() as $planet) {
            if ($planet->getShipUnits()->getAmountByMachineName($shipName) > 0) {
                return $planet;
            }
        }

        return null;
    }

    /** @return array<string, true> coordinates already being harvested */
    private function coveredCoordinates(int $playerId): array
    {
        $coordinates = [];
        foreach (FleetMission::query()
            ->where('user_id', $playerId)
            ->where('mission_type', RecycleMission::getTypeId())
            ->where('processed', 0)
            ->where('canceled', 0)
            ->get(['galaxy_to', 'system_to', 'position_to']) as $mission) {
            $coordinates["{$mission->galaxy_to}:{$mission->system_to}:{$mission->position_to}"] = true;
        }

        return $coordinates;
    }

    /** @return array<string, true> coordinates already decided but not yet dispatched */
    private function openRecycleIntentCoordinates(int $playerId): array
    {
        $coordinates = [];
        foreach (AiWorkItem::query()
            ->where('player_id', $playerId)
            ->where('kind', AiWorkKind::Recycle)
            ->whereIn('state', [AiWorkState::Pending, AiWorkState::Leased, AiWorkState::Retry])
            ->get(['payload']) as $item) {
            $payload = $item->payload ?? [];
            if (isset($payload['target_galaxy'], $payload['target_system'], $payload['target_position'])) {
                $coordinates["{$payload['target_galaxy']}:{$payload['target_system']}:{$payload['target_position']}"] = true;
            }
        }

        return $coordinates;
    }
}
