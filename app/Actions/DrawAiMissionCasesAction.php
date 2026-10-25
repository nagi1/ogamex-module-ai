<?php

namespace Modules\AI\Actions;

use Exception;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Modules\AI\Support\SimulatedTime;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\DeploymentMission;
use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\RecycleMission;
use OGame\GameMissions\TransportMission;
use OGame\GameObjects\Models\UnitObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\WreckField;
use OGame\Services\CharacterClassService;
use OGame\Services\DebrisFieldService;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Training only (plan/rl/world-sim-spec.md, `world`): random missions answered by the host, the parity cases of the
 * simulator's mission rules. Each case draws classes, research, a fleet, cargo, speed and both planets on real accounts,
 * dispatches through `FleetMissionService::createNewFromPlanet` (the dispatch form's own call), moves the clock to the
 * arrival and the return and processes each through `processDueMissionEventsForMissionId` (the arrival job's own call).
 * Every case runs in a savepoint rolled back before the next, and the whole draw in one transaction rolled back at the
 * end. Units, research and buildings are named by host id only.
 */
class DrawAiMissionCasesAction
{
    public const VERSION = 1;

    /** The missions this export covers, by the name the command takes. */
    public const MISSIONS = [
        'transport' => TransportMission::class,
        'deploy' => DeploymentMission::class,
        'espionage' => EspionageMission::class,
        'attack' => AttackMission::class,
        'recycle' => RecycleMission::class,
    ];

    /** The dispatch form's speed values (FleetController::dispatchSendFleet); 0.5 is the General's extra step. */
    private const SPEEDS = [1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5, 5.0, 5.5, 6.0, 6.5, 7.0, 7.5, 8.0, 8.5, 9.0, 9.5, 10.0];

    /** Inputs a player gets wrong, per mission, drawn in one case of eight so the host's refusals are covered. */
    private const PERTURBATIONS = [
        'transport' => ['fuel_short', 'over_cargo', 'missing_units', 'wrong_target'],
        'deploy' => ['fuel_short', 'over_cargo', 'missing_units', 'wrong_target'],
        'espionage' => ['fuel_short', 'missing_units', 'missing_required', 'wrong_target'],
        'attack' => ['fuel_short', 'over_cargo', 'missing_units', 'wrong_target'],
        'recycle' => ['fuel_short', 'missing_units', 'missing_required', 'wrong_target'],
    ];

    private const MAX_AMOUNT = 300;

    private const MAX_STOCK = 5_000_000;

    private Randomizer $random;

    private int $maxLevel = 12;

    /** @var array<int, int> defence id => most a planet may hold */
    private array $caps = [];

    /**
     * @param  list<PlayerService>  $accounts  at least two, each with a planet
     * @param  list<string>  $missions  keys of MISSIONS, drawn in turn
     * @return array<string, mixed>
     */
    public function handle(array $accounts, int $count, array $missions, int $maxLevel, int $seed): array
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->maxLevel = $maxLevel;
        $start = SimulatedTime::now()->getTimestamp();
        $ids = array_map(static fn (PlayerService $player): int => $player->getId(), $accounts);

        DB::beginTransaction();
        $settings = app(SettingsService::class);
        $engine = $settings->battleEngine();
        // Host PHP has no FFI, so the battles are the PHP engine's; the same seed replays them.
        $settings->set('battle_engine', 'php');
        $this->caps = $this->defenceCaps($ids[0]);
        $fleetSettings = app(ExportAiFleetSettingsAction::class)->handle($this->freshPlayer($ids[0]));

        $cases = [];
        for ($i = 0; $i < $count; $i++) {
            $cases[] = $this->isolated($ids, $missions[$i % count($missions)], $start, ($seed * 1_000_003 + $i) & 0x7FFFFFFF, $i);
        }

        $settings->set('battle_engine', $engine);
        DB::rollBack();
        SimulatedTime::freezeAt(Date::createFromTimestamp($start));
        app()->forgetInstance(Randomizer::class);
        $this->forget();

        return [
            'version' => self::VERSION,
            'seed' => $seed,
            'max_level' => $maxLevel,
            'battle_engine' => 'php',
            'start' => $start,
            'accounts' => $ids,
            'fleet_settings' => $fleetSettings,
            'cases' => $cases,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function isolated(array $ids, string $mission, int $start, int $caseSeed, int $index): array
    {
        DB::beginTransaction();
        try {
            return ['index' => $index] + $this->case($ids, $mission, $start, $caseSeed);
        } finally {
            DB::rollBack();
            $this->forget();
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function case(array $ids, string $mission, int $start, int $caseSeed): array
    {
        SimulatedTime::freezeAt(Date::createFromTimestamp($start));
        // Setup draws (a colony's size) come from their own stream, so the case seed starts at the dispatch.
        app()->instance(Randomizer::class, new Randomizer(new Mt19937($caseSeed ^ 0x5BD1E995)));
        $class = self::MISSIONS[$mission];
        $perturbation = $this->random->getInt(1, 8) === 1 ? $this->pick(self::PERTURBATIONS[$mission]) : null;

        $originOwner = $this->pick($ids);
        $originPlanetId = $this->pick($this->freshPlayer($originOwner)->planets->allPlanets())->getPlanetId();
        $this->drawPlayer($originOwner, $mission === 'attack');
        [$targetCoordinate, $targetType, $targetPlanetId] = $this->target($mission, $perturbation, $ids, $originOwner, $originPlanetId);
        $targetOwner = $targetPlanetId === null ? null : (int) Planet::query()->whereKey($targetPlanetId)->value('user_id');
        if ($targetOwner !== null && $targetOwner !== $originOwner) {
            $this->drawPlayer($targetOwner, $mission === 'attack');
        }
        $this->forget();

        $origin = $this->freshPlanet($originPlanetId);
        $player = $origin->getPlayer() ?? throw new Exception('The origin planet has no owner.');
        $units = $this->drawFleet($mission, $perturbation, $targetCoordinate);
        $speed = $this->drawSpeed($player);
        $cargo = $this->drawCargo($mission, $perturbation, $units->getTotalCargoCapacity($player));
        $fuel = (int) app()->makeWith(FleetMissionService::class, ['player' => $player])->calculateConsumption($origin, $units, $targetCoordinate, 0, $speed);

        $this->stockOrigin($originPlanetId, $units, $cargo, $fuel, $perturbation, $start);
        if ($targetPlanetId !== null && $targetPlanetId !== $originPlanetId) {
            $this->stockTarget($targetPlanetId, in_array($mission, ['attack', 'espionage'], true), $start);
        }
        $this->forget();
        $this->freshPlanet($originPlanetId)->update();
        if ($targetPlanetId !== null) {
            $this->freshPlanet($targetPlanetId)->update();
        }

        $input = [
            'origin' => $this->planetState($originPlanetId),
            'target' => $targetPlanetId === null ? null : $this->planetState($targetPlanetId),
            'target_coordinates' => [$targetCoordinate->galaxy, $targetCoordinate->system, $targetCoordinate->position],
            'target_type' => $targetType->value,
            'origin_player' => $this->playerState($originOwner),
            'target_player' => $targetOwner === null ? null : $this->playerState($targetOwner),
            'units' => (object) $this->counts($units),
            'cargo' => $cargo,
            'speed' => $speed,
            'holding_hours' => 0,
            'debris_before' => $this->debris($targetCoordinate),
        ];

        $result = [
            'mission' => $mission,
            'mission_type' => $class::getTypeId(),
            'case_seed' => $caseSeed,
            'perturbation' => $perturbation,
            'input' => $input,
        ];

        return $result + $this->play($class, $originPlanetId, $targetCoordinate, $targetType, $units, $cargo, $speed, $caseSeed);
    }

    /**
     * Dispatch, arrival and return, each through the host's own call at its own second.
     *
     * @param  class-string  $class
     * @param  list<int>  $cargo
     * @return array<string, mixed>
     */
    private function play(string $class, int $originPlanetId, Coordinate $targetCoordinate, PlanetType $targetType, UnitCollection $units, array $cargo, float $speed, int $caseSeed): array
    {
        $origin = $this->freshPlanet($originPlanetId);
        $player = $origin->getPlayer() ?? throw new Exception('The origin planet has no owner.');
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $reports = [EspionageReport::query()->max('id') ?? 0, BattleReport::query()->max('id') ?? 0];
        $hadMoon = $this->moonAt($targetCoordinate) !== null;

        app()->instance(Randomizer::class, new Randomizer(new Mt19937($caseSeed)));
        try {
            $sent = $fleetMissions->createNewFromPlanet($origin, $targetCoordinate, $targetType, $class::getTypeId(), $units, new Resources($cargo[0], $cargo[1], $cargo[2], 0), $speed);
        } catch (Exception $refusal) {
            return ['dispatch' => ['accepted' => false, 'error' => $refusal->getMessage()], 'arrival' => null, 'return' => null];
        }

        $dispatch = [
            'accepted' => true,
            'error' => null,
            'departure' => $sent->time_departure,
            'arrival' => $sent->time_arrival,
            'fuel' => (int) $sent->deuterium_consumption,
            'origin_after' => $this->planetState($originPlanetId),
        ];

        SimulatedTime::freezeAt(Date::createFromTimestamp($sent->time_arrival));
        $targetBefore = $sent->planet_id_to === null ? null : $this->updatedState($sent->planet_id_to);
        $replay = $class === AttackMission::class ? $this->replayBattle($sent, $caseSeed) : null;
        $fleetMissions->processDueMissionEventsForMissionId($sent->id);
        $this->forget();

        $back = FleetMission::query()->where('parent_id', $sent->id)->first();
        $arrival = [
            'time' => $sent->time_arrival,
            'target_before' => $targetBefore,
            'target_after' => $sent->planet_id_to === null ? null : $this->planetState($sent->planet_id_to),
            'debris_after' => $this->debris($targetCoordinate),
            'moon' => $hadMoon ? null : $this->moonAt($targetCoordinate),
            'wreck_field' => $this->wreckField($targetCoordinate),
            'return_mission' => $back === null ? null : $this->flight($back),
            'espionage' => $class === EspionageMission::class ? $this->espionage($reports, $caseSeed) : null,
            'battle' => $class === AttackMission::class ? $this->battle($reports[1], $replay, $back) : null,
        ];

        return ['dispatch' => $dispatch, 'arrival' => $arrival, 'return' => $back === null ? null : $this->comeHome($fleetMissions, $back, $originPlanetId)];
    }

    /** @return array<string, mixed> */
    private function comeHome(FleetMissionService $fleetMissions, FleetMission $back, int $originPlanetId): array
    {
        SimulatedTime::freezeAt(Date::createFromTimestamp($back->time_arrival));
        $before = $this->updatedState($originPlanetId);
        $fleetMissions->processDueMissionEventsForMissionId($back->id);
        $this->forget();

        return [
            'time' => $back->time_arrival,
            'origin_before' => $before,
            'origin_after' => $this->planetState($originPlanetId),
            'wreck_field' => $this->wreckField(new Coordinate((int) $back->galaxy_to, (int) $back->system_to, (int) $back->position_to)),
        ];
    }

    /**
     * The target for a mission: a foreign planet, an own one, an empty slot or a debris field, as the mission needs and
     * the perturbation spoils.
     *
     * @param  list<int>  $ids
     * @return array{0: Coordinate, 1: PlanetType, 2: int|null}
     */
    private function target(string $mission, string|null $perturbation, array $ids, int $originOwner, int $originPlanetId): array
    {
        $wrong = $perturbation === 'wrong_target';
        $foreign = fn (): PlanetService => $this->foreignPlanet($ids, $originOwner);
        $own = fn (): PlanetService => $this->ownOtherPlanet($originOwner, $originPlanetId);

        $planet = match ($mission) {
            'transport' => $wrong ? null : ($this->random->getInt(0, 1) === 1 ? $own() : $foreign()),
            'deploy' => $wrong ? $foreign() : $own(),
            'espionage', 'attack' => $wrong ? $own() : $foreign(),
            default => null,
        };
        if ($planet !== null) {
            return [$planet->getPlanetCoordinates(), PlanetType::Planet, $planet->getPlanetId()];
        }
        if ($mission === 'transport') {
            return [$this->emptySlot($originPlanetId), PlanetType::Planet, null];
        }

        return $this->debrisTarget($ids, $originPlanetId, $wrong);
    }

    /**
     * Battle debris at a planet or an empty slot, or expedition debris at slot 16; none when the target is spoiled.
     *
     * @param  list<int>  $ids
     * @return array{0: Coordinate, 1: PlanetType, 2: null}
     */
    private function debrisTarget(array $ids, int $originPlanetId, bool $wrong): array
    {
        $kind = $this->random->getInt(1, 10);
        $coordinate = match (true) {
            $kind <= 6 => $this->freshPlanet($this->pick($this->planetIds($ids)))->getPlanetCoordinates(),
            $kind <= 9 => $this->emptySlot($originPlanetId),
            default => $this->deepSpace($originPlanetId),
        };
        if (!$wrong) {
            $field = app(DebrisFieldService::class);
            $field->loadOrCreateForCoordinates($coordinate);
            // One field in ten is a harvested "ghost": it stays a valid target with nothing in it.
            $empty = $this->random->getInt(1, 10) === 1;
            $amount = fn (): int => $empty ? 0 : $this->logUniform(2_000_000);
            $field->appendResources(new Resources($amount(), $amount(), $amount(), 0));
            $field->save();
        }

        return [$coordinate, PlanetType::DebrisField, null];
    }

    /** @param list<int> $ids */
    private function foreignPlanet(array $ids, int $owner): PlanetService
    {
        $others = array_values(array_diff($ids, [$owner]));

        return $this->pick($this->freshPlayer($this->pick($others))->planets->allPlanets());
    }

    /** Another planet of the same owner; a colony is founded near the origin when the account has only one. */
    private function ownOtherPlanet(int $owner, int $originPlanetId): PlanetService
    {
        $player = $this->freshPlayer($owner);
        $others = array_values(array_filter($player->planets->allPlanets(), static fn (PlanetService $planet): bool => $planet->getPlanetId() !== $originPlanetId));
        if ($others !== []) {
            return $this->pick($others);
        }

        return app(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer($player, $this->emptySlot($originPlanetId));
    }

    private function emptySlot(int $originPlanetId): Coordinate
    {
        $origin = $this->freshPlanet($originPlanetId)->getPlanetCoordinates();
        $factory = app(PlanetServiceFactory::class);
        $systems = app(SettingsService::class)->numberOfSystems();
        do {
            $coordinate = new Coordinate($origin->galaxy, max(1, min($systems, $origin->system + $this->random->getInt(-5, 5))), $this->random->getInt(1, 15));
        } while ($factory->planetExistsAtCoordinate($coordinate) || $this->debris($coordinate) !== null);

        return $coordinate;
    }

    private function deepSpace(int $originPlanetId): Coordinate
    {
        $origin = $this->freshPlanet($originPlanetId)->getPlanetCoordinates();

        return new Coordinate($origin->galaxy, $origin->system, 16);
    }

    /** A class (no General in a battle, whose Hamill manoeuvre the simulator leaves out) and every research drawn. */
    private function drawPlayer(int $id, bool $battle): void
    {
        $classes = array_values(array_filter(CharacterClass::cases(), static fn (CharacterClass $class): bool => !$battle || $class !== CharacterClass::GENERAL));
        // The simulator has no tactical retreat (raid-sim-spec.md), so a defender never flees.
        DB::table('users')->where('id', $id)->update(['character_class' => $this->pick($classes)->value, 'tactical_retreat_ratio' => 0]);
        $levels = [];
        foreach (ObjectService::getResearchObjects() as $research) {
            $levels[$research->machine_name] = $this->random->getInt(0, $this->maxLevel);
        }
        DB::table('users_tech')->where('user_id', $id)->update($levels);
    }

    private function drawFleet(string $mission, string|null $perturbation, Coordinate $target): UnitCollection
    {
        $flying = array_values(array_filter(ObjectService::getShipObjects(), static fn (UnitObject $ship): bool => $ship->properties->speed->rawValue > 0));
        $required = match ($mission) {
            'espionage' => EspionageMission::getRequiredShipMachineNames(),
            'recycle' => [RecycleMission::getHarvesterMachineNameForPosition($target->position)],
            default => [],
        };
        $units = new UnitCollection();
        if ($perturbation !== 'missing_required') {
            foreach ($required as $name) {
                $units->addUnit(ObjectService::getUnitObjectByMachineName($name), $this->logUniform($mission === 'espionage' ? 30 : 100));
            }
        }

        $others = array_values(array_filter($flying, static fn (UnitObject $ship): bool => !in_array($ship->machine_name, $required, true)));
        $extra = $required === [] ? $this->random->getInt(1, 4) : ($this->random->getInt(1, 4) === 1 || $units->getAmount() === 0 ? $this->random->getInt(1, 2) : 0);
        foreach (array_slice($this->random->shuffleArray($others), 0, $extra) as $ship) {
            $units->addUnit($ship, $this->logUniform(self::MAX_AMOUNT));
        }

        return $units;
    }

    private function drawSpeed(PlayerService $player): float
    {
        $speeds = self::SPEEDS;
        if (app(CharacterClassService::class)->hasDetailedFleetSpeedSettings($player->getUser())) {
            $speeds[] = 0.5;
        }

        return $this->pick($speeds);
    }

    /** @return list<int> metal, crystal, deuterium carried */
    private function drawCargo(string $mission, string|null $perturbation, int $capacity): array
    {
        if ($perturbation === 'over_cargo') {
            $over = $capacity + $this->random->getInt(1, max(1, intdiv($capacity, 10)));

            return [$over - 2 * intdiv($over, 3), intdiv($over, 3), intdiv($over, 3)];
        }
        $share = match ($mission) {
            'transport', 'deploy' => $this->random->getFloat(0, 1),
            'attack' => $this->random->getInt(1, 10) <= 3 ? $this->random->getFloat(0, 0.6) : 0.0,
            default => $this->random->getInt(1, 10) === 1 ? $this->random->getFloat(0, 0.3) : 0.0,
        };
        $weights = [$this->random->getFloat(0, 1), $this->random->getFloat(0, 1), $this->random->getFloat(0, 1)];
        $sum = array_sum($weights) ?: 1.0;

        return array_map(static fn (float $weight): int => (int) floor($capacity * $share * $weight / $sum), $weights);
    }

    /**
     * The origin holds the fleet (one short when the perturbation says so), the cargo and the fuel (short when it says
     * so), and a random surplus of each.
     *
     * @param  list<int>  $cargo
     */
    private function stockOrigin(int $planetId, UnitCollection $units, array $cargo, int $fuel, string|null $perturbation, int $start): void
    {
        $columns = $this->zeroUnits();
        foreach ($units->units as $entry) {
            $columns[$entry->unitObject->machine_name] = $entry->amount + ($this->random->getInt(0, 3) === 0 ? $this->logUniform(50) : 0);
        }
        if ($perturbation === 'missing_units' && $units->units !== []) {
            $short = $this->pick(array_values($units->units))->unitObject->machine_name;
            $columns[$short] = $this->counts($units)[ObjectService::getUnitObjectByMachineName($short)->id] - 1;
        }

        $fuelShare = $perturbation === 'fuel_short' ? $this->random->getFloat(0, 0.95) : $this->random->getFloat(1, 3);
        $surplus = fn (): int => $this->random->getInt(0, 1) === 1 ? $this->logUniform(self::MAX_STOCK) : 0;
        $columns['metal'] = $cargo[0] + $surplus();
        $columns['crystal'] = $cargo[1] + $surplus();
        $columns['deuterium'] = $cargo[2] + (int) floor($fuel * $fuelShare) + ($perturbation === 'fuel_short' ? 0 : $surplus());
        $columns['time_last_update'] = $start;
        DB::table('planets')->where('id', $planetId)->update($columns);
    }

    /** Stock on the target, and ships and defences when it is to be spied on or attacked. */
    private function stockTarget(int $planetId, bool $armed, int $start): void
    {
        $columns = $this->zeroUnits();
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $columns[$resource] = $this->random->getInt(1, 10) === 1 ? 0 : $this->logUniform(self::MAX_STOCK);
        }
        if ($armed) {
            foreach (array_slice($this->random->shuffleArray(ObjectService::getShipObjects()), 0, $this->random->getInt(0, 3)) as $ship) {
                $columns[$ship->machine_name] = $this->logUniform(self::MAX_AMOUNT);
            }
            foreach (array_slice($this->random->shuffleArray(ObjectService::getDefenseObjects()), 0, $this->random->getInt(0, 4)) as $defence) {
                $columns[$defence->machine_name] = min($this->logUniform(self::MAX_AMOUNT), $this->caps[$defence->id] ?? 0);
            }
        }
        $columns['time_last_update'] = $start;
        DB::table('planets')->where('id', $planetId)->update($columns);
    }

    /** @return array<string, int> every unit column at zero */
    private function zeroUnits(): array
    {
        $columns = [];
        foreach (ObjectService::getUnitObjects() as $unit) {
            $columns[$unit->machine_name] = 0;
        }

        return $columns;
    }

    /**
     * The most of each defence the host lets a planet hold (`getObjectMaxBuildAmount` on a rich planet in memory).
     *
     * @return array<int, int>
     */
    private function defenceCaps(int $owner): array
    {
        $planet = $this->freshPlayer($owner)->planets->current();
        $planet->addResources(new Resources(1e13, 1e13, 1e13, 0), false);
        $caps = [];
        foreach (ObjectService::getDefenseObjects() as $defence) {
            $caps[$defence->id] = ObjectService::getObjectMaxBuildAmount($defence->machine_name, $planet, true);
        }
        $this->forget();

        return $caps;
    }

    /**
     * The fight the host is about to run, replayed in memory with the seed the case's random stream hands the engine
     * first, so the recorded seed is shown to reproduce the host's battle.
     *
     * @return array<string, mixed>
     */
    private function replayBattle(FleetMission $sent, int $caseSeed): array
    {
        $seed = (new Randomizer(new Mt19937($caseSeed)))->getInt(0, PHP_INT_MAX);
        $target = $this->freshPlanet((int) $sent->planet_id_to);
        $attacker = $this->freshPlanet((int) $sent->planet_id_from)->getPlayer() ?? throw new Exception('The attacker has no owner.');
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $attacker]);

        $fleet = new AttackerFleet();
        $fleet->units = $fleetMissions->getFleetUnits($sent);
        $fleet->player = $attacker;
        $fleet->fleetMissionId = $sent->id;
        $fleet->ownerId = $attacker->getId();
        $fleet->cargoResources = $fleetMissions->getResources($sent);
        $fleet->isInitiator = true;
        $fleet->fleetMission = $sent;
        $result = (new PhpBattleEngine([$fleet], $target, [DefenderFleet::fromPlanet($target)], app(SettingsService::class)))->simulateBattle($seed, true);
        $this->forget();

        return [
            'seed' => $seed,
            'attacker_left' => $this->counts($result->attackerUnitsResult),
            'loot' => $this->triple($result->loot),
            'debris' => $this->triple($result->debris),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $replay
     * @return array<string, mixed>|null
     */
    private function battle(int $after, array|null $replay, FleetMission|null $back): array|null
    {
        $report = BattleReport::query()->where('id', '>', $after)->orderBy('id')->first();
        if ($report === null || $replay === null) {
            return null;
        }
        $summary = $this->battleReport($report);
        $returned = $back === null ? [] : $this->counts(app()->makeWith(FleetMissionService::class, ['player' => $this->freshPlayer((int) $back->user_id)])->getFleetUnits($back));
        $loot = $report->loot ?? [];

        return [
            'seed' => $replay['seed'],
            'replays' => $replay['attacker_left'] == $returned
                && $replay['loot'] == [(int) ($loot['metal'] ?? 0), (int) ($loot['crystal'] ?? 0), (int) ($loot['deuterium'] ?? 0)]
                && $replay['debris'] == $summary['debris'],
            'report' => $summary,
        ];
    }

    /**
     * The report the host made, and the counter-espionage roll and battle seed the case's random stream gave: a roll
     * only when the chance is strictly between 0 and 100, a seed only when the battle was fought.
     *
     * @param  array{0: int, 1: int}  $after
     * @return array<string, mixed>
     */
    private function espionage(array $after, int $caseSeed): array
    {
        $report = EspionageReport::query()->where('id', '>', $after[0])->orderBy('id')->firstOrFail();
        $battle = BattleReport::query()->where('id', '>', $after[1])->orderBy('id')->first();
        $chance = (int) $report->counter_espionage_chance;
        $stream = new Randomizer(new Mt19937($caseSeed));
        $roll = $chance > 0 && $chance < 100 ? $stream->getInt(1, 100) : null;
        $fought = $chance >= 100 || ($roll !== null && $roll <= $chance);

        // A section the probes could not reveal is stored as null, whatever the model's cast says.
        $sections = array_filter(['ships' => $report->getAttribute('ships'), 'defence' => $report->getAttribute('defense'), 'buildings' => $report->getAttribute('buildings'), 'research' => $report->getAttribute('research')], static fn ($section): bool => is_array($section));
        $debris = $report->getAttribute('debris');

        return [
            'counter_espionage_chance' => $chance,
            'roll' => $roll,
            'counter_battle' => $battle !== null,
            'roll_consistent' => $fought === ($battle !== null),
            'battle_seed' => $fought ? $stream->getInt(0, PHP_INT_MAX) : null,
            'sections' => array_keys($sections),
            'resources' => array_values(array_map('intval', $report->resources ?? [])),
            'debris' => is_array($debris) ? array_values(array_map('intval', $debris)) : null,
            'revealed' => (object) $this->revealed($sections),
            'battle' => $battle === null ? null : $this->battleReport($battle),
        ];
    }

    /**
     * A revealed section by object id: units at zero left out, every building and research level kept.
     *
     * @param  array<string, array<string, int>>  $sections
     * @return array<string, object>
     */
    private function revealed(array $sections): array
    {
        $revealed = [];
        foreach ($sections as $name => $section) {
            $revealed[$name] = (object) $this->byId($section, in_array($name, ['buildings', 'research'], true));
        }

        return $revealed;
    }

    /** @return array<string, mixed> */
    private function battleReport(BattleReport $report): array
    {
        $rounds = $report->rounds ?? [];
        $last = $rounds === [] ? null : end($rounds);
        $side = fn (array $side): array => [
            'player_id' => $side['player_id'] ?? null,
            'units' => (object) $this->byId($side['units'] ?? []),
            'resource_loss' => $side['resource_loss'] ?? 0,
            'weapon' => $side['weapon_technology'] ?? 0,
            'shield' => $side['shielding_technology'] ?? 0,
            'armour' => $side['armor_technology'] ?? 0,
        ];
        $loot = $report->loot ?? [];
        $debris = $report->debris ?? [];

        return [
            'moon_existed' => (bool) ($report->general['moon_existed'] ?? false),
            'moon_chance' => (int) ($report->general['moon_chance'] ?? 0),
            'moon_created' => (bool) ($report->general['moon_created'] ?? false),
            'attacker' => $side($report->attacker ?? []),
            'defender' => $side($report->defender ?? []),
            'loot_percentage' => $loot['percentage'] ?? 0,
            'loot' => [(int) ($loot['metal'] ?? 0), (int) ($loot['crystal'] ?? 0), (int) ($loot['deuterium'] ?? 0)],
            'debris' => [(int) ($debris['metal'] ?? 0), (int) ($debris['crystal'] ?? 0), (int) ($debris['deuterium'] ?? 0)],
            'repaired' => (object) $this->byId($report->repaired_defenses ?? []),
            'rounds' => count($rounds),
            'attacker_left' => (object) ($last === null ? $this->byId($report->attacker['units'] ?? []) : $this->byId($last['attacker_ships'] ?? [])),
            'defender_left' => (object) ($last === null ? $this->byId($report->defender['units'] ?? []) : $this->byId($last['defender_ships'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    private function flight(FleetMission $mission): array
    {
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $this->freshPlayer((int) $mission->user_id)]);

        return [
            'departure' => $mission->time_departure,
            'arrival' => $mission->time_arrival,
            'units' => (object) $this->counts($fleetMissions->getFleetUnits($mission)),
            'cargo' => [(int) $mission->metal, (int) $mission->crystal, (int) $mission->deuterium],
        ];
    }

    /** @return array<string, mixed> the planet after the host's own update at the current second */
    private function updatedState(int $planetId): array
    {
        $this->freshPlanet($planetId)->update();
        $this->forget();

        return $this->planetState($planetId);
    }

    /** @return array<string, mixed> */
    private function planetState(int $planetId): array
    {
        $planet = $this->freshPlanet($planetId);
        $at = $planet->getPlanetCoordinates();

        return [
            'id' => $planetId,
            'owner' => $planet->getPlayer()?->getId(),
            'coordinates' => [$at->galaxy, $at->system, $at->position],
            'type' => $planet->getPlanetType()->value,
            'resources' => [$planet->metal()->get(), $planet->crystal()->get(), $planet->deuterium()->get()],
            'ships' => (object) $this->counts($planet->getShipUnits()),
            'defence' => (object) $this->counts($planet->getDefenseUnits()),
            'time_last_update' => $planet->getUpdatedAt()->getTimestamp(),
        ];
    }

    /** @return array<string, mixed> */
    private function playerState(int $id): array
    {
        $player = $this->freshPlayer($id);
        $research = [];
        foreach (ObjectService::getResearchObjects() as $object) {
            $research[$object->id] = $player->getResearchLevel($object->machine_name);
        }

        return ['id' => $id, 'class' => $player->getUser()->character_class, 'research' => (object) $research];
    }

    /** @return list<int>|null */
    private function debris(Coordinate $at): array|null
    {
        $field = DebrisField::query()->where('galaxy', $at->galaxy)->where('system', $at->system)->where('planet', $at->position)->first();

        return $field === null ? null : [(int) $field->metal, (int) $field->crystal, (int) $field->deuterium];
    }

    /** @return array<string, mixed>|null */
    private function moonAt(Coordinate $at): array|null
    {
        $moon = Planet::query()->where('galaxy', $at->galaxy)->where('system', $at->system)->where('planet', $at->position)
            ->where('planet_type', PlanetType::Moon->value)->first();

        return $moon === null ? null : ['id' => $moon->id, 'diameter' => $moon->diameter, 'fields' => $moon->field_max];
    }

    /** @return array<string, mixed>|null */
    private function wreckField(Coordinate $at): array|null
    {
        $field = WreckField::query()->where('galaxy', $at->galaxy)->where('system', $at->system)->where('planet', $at->position)->first();

        return $field === null ? null : ['owner' => $field->owner_player_id, 'status' => $field->status, 'ships' => $field->ship_data];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function planetIds(array $ids): array
    {
        return array_values(Planet::query()->whereIn('user_id', $ids)->where('planet_type', PlanetType::Planet->value)->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    private function freshPlayer(int $id): PlayerService
    {
        return app(PlayerServiceFactory::class)->make($id, true);
    }

    private function freshPlanet(int $id): PlanetService
    {
        return app(PlanetServiceFactory::class)->make($id, true) ?? throw new Exception("Planet {$id} not found.");
    }

    /** The host caches services per id; a rolled-back or host-written row must be read again. */
    private function forget(): void
    {
        app(PlayerServiceFactory::class)->clearInstances();
        app(PlanetServiceFactory::class)->clearInstances();
    }

    /**
     * @param  array<string, int>  $byName
     * @return array<int, int> object id => value; a unit at zero is left out, a level at zero kept when asked
     */
    private function byId(array $byName, bool $keepZeros = false): array
    {
        $byId = [];
        foreach ($byName as $name => $value) {
            if ($keepZeros || (int) $value !== 0) {
                $byId[ObjectService::getObjectByMachineName((string) $name)->id] = (int) $value;
            }
        }
        ksort($byId);

        return $byId;
    }

    /** @return array<int, int> unit id => amount, zeros left out */
    private function counts(UnitCollection $units): array
    {
        $counts = [];
        foreach ($units->units as $entry) {
            if ($entry->amount > 0) {
                $counts[$entry->unitObject->id] = ($counts[$entry->unitObject->id] ?? 0) + $entry->amount;
            }
        }
        ksort($counts);

        return $counts;
    }

    /** @return list<int> */
    private function triple(Resources $resources): array
    {
        return [(int) $resources->metal->get(), (int) $resources->crystal->get(), (int) $resources->deuterium->get()];
    }

    private function logUniform(int $max): int
    {
        return max(1, min($max, (int) floor(exp($this->random->getFloat(0, log($max + 1))))));
    }

    /**
     * @template T
     * @param  array<int, T>  $values
     * @return T
     */
    private function pick(array $values): mixed
    {
        $values = array_values($values);

        return $values[$this->random->getInt(0, count($values) - 1)];
    }
}
