<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\UnitObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Services\CoordinateDistanceCalculator;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * Training only (plan/rl/world-sim-spec.md, `fleet` parity): random flights answered by the host itself. Each group
 * draws universe settings and who is active, then flights between random coordinates with random fleets, drives and
 * speeds, and records FleetMissionService's own distance, duration and fuel. The caller rolls the writes back.
 */
class DrawAiFleetCasesAction
{
    public const VERSION = 1;

    /** Speed as the dispatch form sends it: 1 is 10%, 10 is 100%, in 5% steps (FleetController). */
    private const SPEEDS = [0.5, 1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5, 5.0, 5.5, 6.0, 6.5, 7.0, 7.5, 8.0, 8.5, 9.0, 9.5, 10.0];

    private const FLEET_SPEEDS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    /**
     * @return array<string, mixed>
     */
    public function handle(PlayerService $player, int $count, int $perGroup, int $maxLevel, int $seed): array
    {
        mt_srand($seed);
        $groups = [];
        for ($drawn = 0; $drawn < $count; $drawn += $perGroup) {
            $groups[] = $this->group($player, min($perGroup, $count - $drawn), $maxLevel);
        }

        return ['version' => self::VERSION, 'player_id' => $player->getId(), 'seed' => $seed, 'max_level' => $maxLevel, 'groups' => $groups];
    }

    /**
     * @return array<string, mixed>
     */
    private function group(PlayerService $player, int $count, int $maxLevel): array
    {
        $this->drawSettings();
        $occupancy = $this->occupancy();
        $this->drawSettingFlags();
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        // A detached copy moved to each case's origin, so no planet is changed and no factory cache learns it.
        $originModel = Planet::query()->where('user_id', $player->getId())->firstOrFail()->replicate();
        $origin = app(PlanetServiceFactory::class)->makeFromModel($originModel, $player);
        $settings = app(ExportAiFleetSettingsAction::class)->handle($player);
        $missions = array_map('intval', array_keys($settings['mission_fleet_speed']));

        $cases = [];
        for ($i = 0; $i < $count; $i++) {
            $cases[] = $this->flight($player, $fleetMissions, $originModel, $origin, $missions, $maxLevel);
        }

        return ['settings' => $settings, 'occupied' => $occupancy, 'cases' => $cases];
    }

    private function drawSettings(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('number_of_galaxies', mt_rand(1, 9));
        $settings->set('number_of_systems', mt_rand(1, 4) === 1 ? mt_rand(10, 60) : mt_rand(100, 499));
        foreach (['fleet_speed', 'fleet_speed_war', 'fleet_speed_holding', 'fleet_speed_peaceful'] as $key) {
            $settings->set($key, $this->pick(self::FLEET_SPEEDS));
        }
        $settings->set('deuterium_consumption', (string) $this->pick(SettingsService::DEUTERIUM_CONSUMPTION_OPTIONS));

        $now = Date::now()->getTimestamp();
        foreach (DB::table('users')->pluck('id') as $userId) {
            DB::table('users')->where('id', $userId)->update(['time' => (string) (mt_rand(0, 1) === 1 ? $now : $now - 8 * 86400)]);
        }
    }

    private function drawSettingFlags(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('ignore_empty_systems_on', mt_rand(0, 1));
        $settings->set('ignore_inactive_systems_on', mt_rand(0, 1));
    }

    /**
     * Every system holding a planet, with whether the host counts it inactive; any other system is empty.
     *
     * @return list<array{0: int, 1: int, 2: bool}>
     */
    private function occupancy(): array
    {
        app(SettingsService::class)->set('ignore_inactive_systems_on', 1);
        $calculator = app(CoordinateDistanceCalculator::class);
        $systems = Planet::query()->select(['galaxy', 'system'])->distinct()->orderBy('galaxy')->orderBy('system')->get();

        return array_values($systems->map(static function (Planet $planet) use ($calculator): array {
            $at = new Coordinate($planet->galaxy, $planet->system, 1);

            return [$planet->galaxy, $planet->system, $calculator->getNumInactiveSystems($at, $at) === 1];
        })->all());
    }

    /**
     * @param  list<int>  $missions
     * @return array<string, mixed>
     */
    private function flight(PlayerService $player, FleetMissionService $fleetMissions, Planet $originModel, PlanetService $origin, array $missions, int $maxLevel): array
    {
        $research = [];
        foreach (ObjectService::getResearchObjects() as $object) {
            $research[(string) $object->id] = mt_rand(0, $maxLevel);
            $player->setResearchLevel($object->machine_name, $research[(string) $object->id], false);
        }

        [$from, $to] = $this->coordinates();
        $originModel->galaxy = $from->galaxy;
        $originModel->system = $from->system;
        $originModel->planet = $from->position;

        $units = $this->fleet($player);
        $speed = $this->pick(self::SPEEDS);
        $holding = mt_rand(0, 1) === 1 ? mt_rand(1, 24) : 0;
        $mission = $this->pick($missions);
        $missionObject = GameMissionFactory::getMissionById($mission, []);

        return [
            'from' => [$from->galaxy, $from->system, $from->position],
            'to' => [$to->galaxy, $to->system, $to->position],
            'research' => $research,
            'fleet' => array_map(static fn ($entry): array => [$entry->unitObject->id, $entry->amount], array_values($units->units)),
            'speed_percent' => $speed,
            'holding_hours' => $holding,
            'mission' => $mission,
            'distance' => $fleetMissions->calculateFleetMissionDistance($origin, $to),
            'duration' => $fleetMissions->calculateFleetMissionDuration($origin, $to, $units, $missionObject, $speed),
            'consumption' => (int) $fleetMissions->calculateConsumption($origin, $units, $to, $holding, $speed),
        ];
    }

    /**
     * A pair spread over the cases that branch the distance: other galaxy, other system, same system, same spot.
     * Half the systems fall in the low range where the universe's planets are, so empty and inactive gaps are met.
     *
     * @return array{0: Coordinate, 1: Coordinate}
     */
    private function coordinates(): array
    {
        $settings = app(SettingsService::class);
        $galaxies = $settings->numberOfGalaxies();
        $systems = $settings->numberOfSystems();
        $system = static fn (): int => mt_rand(0, 1) === 1 ? mt_rand(1, min(20, $systems)) : mt_rand(1, $systems);
        $from = new Coordinate(mt_rand(0, 1) === 1 ? 1 : mt_rand(1, $galaxies), $system(), mt_rand(1, 15));
        $kind = mt_rand(1, 20);

        $to = match (true) {
            $kind <= 6 => new Coordinate(mt_rand(1, $galaxies), $system(), mt_rand(1, 16)),
            $kind <= 14 => new Coordinate($from->galaxy, $system(), mt_rand(1, 16)),
            $kind <= 19 => new Coordinate($from->galaxy, $from->system, mt_rand(1, 16)),
            default => new Coordinate($from->galaxy, $from->system, $from->position),
        };

        return [$from, $to];
    }

    private function fleet(PlayerService $player): UnitCollection
    {
        $flying = array_values(array_filter(ObjectService::getShipObjects(), static fn (UnitObject $ship): bool => $ship->properties->speed->calculate($player)->totalValue > 0));
        shuffle($flying);
        $units = app(UnitCollection::class);
        foreach (array_slice($flying, 0, mt_rand(1, 4)) as $ship) {
            $units->addUnit($ship, mt_rand(1, 10 ** mt_rand(0, 5)));
        }

        return $units;
    }

    /**
     * @template T
     * @param  array<int, T>  $values
     * @return T
     */
    private function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }
}
