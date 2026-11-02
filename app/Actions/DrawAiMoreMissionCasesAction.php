<?php

namespace Modules\AI\Actions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Modules\AI\Support\SimulatedTime;
use OGame\Enums\CharacterClass;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AcsDefendMission;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\ColonisationMission;
use OGame\GameMissions\ExpeditionMission;
use OGame\GameMissions\MissileMission;
use OGame\GameMissions\MoonDestructionMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Http\Controllers\GalaxyController;
use OGame\Models\BattleReport;
use OGame\Models\BuddyRequest;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;

/**
 * Training only (plan/rl/world-sim-spec.md, `world`): the second mission referee. DrawAiMissionCasesAction covers
 * transport, deploy, espionage, attack and recycle; this one covers colonise, expedition, missile,
 * moon destruction and ACS defend.
 * Same method: every case runs in a savepoint rolled back before the next, on the universe's real accounts, through
 * the host's own calls (`createNewFromPlanet` for colonise, expedition and moon destruction; `GalaxyController::missileAttack`
 * for missiles), with the frozen clock moved to the arrival (an expedition: arrival plus holding time) and the return,
 * each processed through `processDueMissionEventsForMissionId`. Units, research and buildings are named by host id only.
 *
 * Randomness: the host's mission draws come from the container's Randomizer. Each case binds `Randomizer(Mt19937(case_seed))`
 * at the dispatch, so `case_seed` replays the whole mission. `draws` lists the draws decoded in the host's call order (a
 * value is the raw getInt result in the range shown), and `decode_check` says whether a decoded draw reproduces an
 * effect the host recorded (null: nothing to check). Host PHP has no FFI, so `RustBattleEngine` is aliased to
 * `PhpBattleEngine` for the run (expedition fights and the moon-destruction fight are the PHP engine's, seeded by the
 * first draw after the NPC fleet is generated).
 *
 * File: {version, schema:"more-mission-cases", seed, max_level, battle_engine:"php", start, accounts[], settings{...}, cases[]}.
 * `settings`: fleet speeds (war, peaceful, holding), economy_speed, expedition weights/multipliers, planet_fields_bonus.
 *
 * Case (all):
 *   index, mission ("colonise"|"expedition"|"missile"|"moon_destruction"|"acs_defend"), mission_type (host id 7, 15, 10, 9, 5),
 *   case_seed, perturbation (string|null: an input a player gets wrong, one case in eight),
 *   input{origin: planetState, target: planetState|null, target_coordinates[g,s,p], target_type (1 planet, 2 debris,
 *     3 moon, 4 deep space), origin_player{id,class,research{id:level}}, target_player|null, units{unitId:n}, cargo[m,c,d],
 *     speed, holding_hours},
 *   dispatch{accepted, error|null, departure, arrival, fuel, holding_seconds, origin_after: planetState}
 *     (missile adds http_status; fuel 0), arrival: null when refused, return: null when no fleet flies home or refused.
 *   planetState = {id, owner, coordinates[g,s,p], type, resources[m,c,d], ships{unitId:n}, defence{unitId:n}, time_last_update}
 *   flight = {departure, arrival, units{unitId:n}, cargo[m,c,d]}
 *   messages = [{user, key, params}] every message the host wrote while processing (outcome keys live here).
 *   return = {time, origin_before: planetState, origin_after: planetState, wreck_field}
 *
 * arrival (all): time (second processed), target_before, target_after (planetState|null), debris_after[m,c,d]|null,
 *   return_mission: flight|null, messages.
 *   colonise adds: planet_created, refusal (null | "slot_taken" | "astrophysics" | "cancelled"), max_planets,
 *     planets_before, planets_after (of the sender), new_planet (planetState + {diameter, field_max, field_current,
 *     temp_min, temp_max, name})|null, draws{fields:{range[lo,hi], bonus, draw}, temperature:{range, draw}}|null
 *     (fields = draw + bonus, times the class size multiplier, truncated; diameter = floor(36.14 * fields + 5697.23)).
 *   expedition adds: holding_seconds, outcome_roll{range, draw}, weights{name:w}, outcome_order[names], draws{...}
 *     (variant_roll, variant, multiplier, base_find{range, draw}, resource_type, dm_reward, delay_multiplier,
 *     speedup_percent, ship_types: whichever the outcome consumed), decode_check, rank1_points, user_changes{column:[before,after]},
 *     debris_slot16 [m,c,d]|null, return_delay (return duration minus the outbound duration, seconds), battle{npc_units,
 *     report} for pirates and aliens (the report from the player's side: attacker = NPC), loot is always zero there.
 *   missile adds: missiles_sent, priority, impulse_drive, range, distance, abm{target, parent}, intercepted, effective,
 *     weapon_tech, armour_tech, defence_before{unitId:n}, defence_after{unitId:n}, abm_before, abm_after (target and parent).
 *   moon_destruction adds: moon_before{diameter, fields}, moon_exists_after, battle (battleReport)|null, seed (the fight's
 *     seed, the first draw), rolls{destroy, loss}|null (the next two getInt(1,100) draws, taken only when the attacker won),
 *     attempted, chance{destroy, loss} (host formulas, for the consistency check only), deathstars_before,
 *     deathstars_after (return fleet), roll_consistent.
 *   acs_defend: the sender holds at a buddy's planet (an accepted buddy request, left out by the `not_mates`
 *     perturbation) while a third account's attack, sent at the same second, lands there; `input.raid{origin, player,
 *     units, speed}`. arrival is the attack's second: time, target_before, target_after, debris_after, battle
 *     (battleReport)|null, defend_after (the holding mission's flight after the fight)|null, raid_return (flight)|null.
 *     `return` is the defender's return home, `defend_return` its flight. The hold may end before the attack lands.
 */
class DrawAiMoreMissionCasesAction
{
    public const VERSION = 1;

    /** The missions this export covers, by the name the command takes. */
    public const MISSIONS = [
        'colonise' => ColonisationMission::class,
        'expedition' => ExpeditionMission::class,
        'missile' => MissileMission::class,
        'moon_destruction' => MoonDestructionMission::class,
        'acs_defend' => AcsDefendMission::class,
    ];

    private const SPEEDS = [1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5, 5.0, 5.5, 6.0, 6.5, 7.0, 7.5, 8.0, 8.5, 9.0, 9.5, 10.0];

    private const PERTURBATIONS = [
        'colonise' => ['fuel_short', 'missing_units', 'no_colony_ship', 'slot_occupied', 'deep_space', 'taken_in_flight'],
        'expedition' => ['fuel_short', 'missing_units', 'no_astrophysics', 'not_deep_space'],
        'missile' => ['too_many_missiles', 'own_target', 'no_missiles'],
        'moon_destruction' => ['fuel_short', 'missing_units', 'no_deathstar', 'no_moon', 'target_is_planet'],
        'acs_defend' => ['fuel_short', 'missing_units', 'not_mates'],
    ];

    private const MAX_STOCK = 5_000_000;

    /** The rank-1 score brackets of the expedition find table (ExpeditionMission::getBaseMaxFindFromHighscore). */
    private const BRACKETS = [
        [10_000, 40_000], [100_000, 500_000], [1_000_000, 1_200_000], [5_000_000, 1_800_000], [25_000_000, 2_400_000],
        [50_000_000, 3_000_000], [75_000_000, 3_600_000], [100_000_000, 4_200_000], [PHP_INT_MAX, 5_000_000],
    ];

    private Randomizer $random;

    private int $maxLevel = 12;

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

        // Host PHP has no FFI: the expedition fight calls RustBattleEngine directly, so it becomes the PHP engine.
        if (!class_exists(\OGame\GameMissions\BattleEngine\RustBattleEngine::class, false) && !extension_loaded('ffi')) {
            class_alias(PhpBattleEngine::class, \OGame\GameMissions\BattleEngine\RustBattleEngine::class);
        }

        DB::beginTransaction();
        $settings = app(SettingsService::class);
        $engine = $settings->battleEngine();
        $settings->set('battle_engine', 'php');
        $exported = $this->settings($settings);

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
            'schema' => 'more-mission-cases',
            'seed' => $seed,
            'max_level' => $maxLevel,
            'battle_engine' => 'php',
            'start' => $start,
            'accounts' => $ids,
            'settings' => $exported,
            'cases' => $cases,
        ];
    }

    /** @return array<string, mixed> */
    private function settings(SettingsService $settings): array
    {
        return [
            'fleet_speed_war' => $settings->fleetSpeedWar(),
            'fleet_speed_peaceful' => $settings->fleetSpeedPeaceful(),
            'fleet_speed_holding' => $settings->fleetSpeedHolding(),
            'economy_speed' => $settings->economySpeed(),
            'planet_fields_bonus' => $settings->planetFieldsBonus(),
            'number_of_systems' => $settings->numberOfSystems(),
            'expedition_weights' => [
                'dark_matter' => $settings->expeditionWeightDarkMatter(), 'ships' => $settings->expeditionWeightShips(),
                'resources' => $settings->expeditionWeightResources(), 'delay' => $settings->expeditionWeightDelay(),
                'speedup' => $settings->expeditionWeightSpeedup(), 'nothing' => $settings->expeditionWeightNothing(),
                'black_hole' => $settings->expeditionWeightBlackHole(), 'pirates' => $settings->expeditionWeightPirates(),
                'aliens' => $settings->expeditionWeightAliens(), 'merchant' => $settings->expeditionWeightMerchant(),
            ],
            'expedition_reward_multiplier' => [
                'resources' => $settings->expeditionRewardMultiplierResources(), 'ships' => $settings->expeditionRewardMultiplierShips(),
                'dark_matter' => $settings->expeditionRewardMultiplierDarkMatter(),
            ],
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
            SimulatedTime::freezeAt(Date::createFromTimestamp($start));
            app()->instance(Randomizer::class, new Randomizer(new Mt19937($caseSeed ^ 0x5BD1E995)));
            $perturbation = $this->random->getInt(1, 8) === 1 ? $this->pick(self::PERTURBATIONS[$mission]) : null;

            return ['index' => $index, 'mission' => $mission, 'mission_type' => self::MISSIONS[$mission]::getTypeId(), 'case_seed' => $caseSeed, 'perturbation' => $perturbation]
                + match ($mission) {
                    'colonise' => $this->colonise($ids, $start, $caseSeed, $perturbation),
                    'expedition' => $this->expedition($ids, $start, $caseSeed, $perturbation),
                    'missile' => $this->missile($ids, $start, $caseSeed, $perturbation),
                    'acs_defend' => $this->acsDefend($ids, $start, $caseSeed, $perturbation),
                    default => $this->moonDestruction($ids, $start, $caseSeed, $perturbation),
                };
        } finally {
            DB::rollBack();
            // Merchant offers live in the cache, which a rollback does not undo.
            foreach ($ids as $id) {
                Cache::forget('active_merchant_' . $id);
            }
            $this->forget();
        }
    }

    // ---------------------------------------------------------------- colonise

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function colonise(array $ids, int $start, int $caseSeed, string|null $perturbation): array
    {
        $owner = $this->pick($ids);
        $originId = $this->pick($this->freshPlayer($owner)->planets->allPlanets())->getPlanetId();
        $this->drawPlayer($owner, ['astrophysics' => $this->random->getInt(0, 12)]);
        $this->forget();

        // Existing colonies up to one past the limit, so the arrival check refuses some.
        $player = $this->freshPlayer($owner);
        $wanted = $this->random->getInt(1, $player->getMaxPlanetAmount() + 1);
        $factory = app(PlanetServiceFactory::class);
        while ($player->planets->planetCount() < $wanted) {
            $factory->createAdditionalPlanetForPlayer($player, $this->emptySlot($originId, 1, 15));
            $this->forget();
            $player = $this->freshPlayer($owner);
        }
        $other = $this->pick(array_values(array_diff($ids, [$owner])));

        $occupied = $perturbation === 'slot_occupied' ? $this->pick($this->freshPlayer($other)->planets->allPlanets()) : null;
        $target = $occupied?->getPlanetCoordinates() ?? $this->emptySlot($originId, 1, 15);
        $targetType = PlanetType::Planet;
        if ($perturbation === 'deep_space') {
            $target = new Coordinate($target->galaxy, $target->system, 16);
        }
        $this->forget();

        $origin = $this->freshPlanet($originId);
        $units = new UnitCollection();
        if ($perturbation !== 'no_colony_ship') {
            $units->addUnit(ObjectService::getUnitObjectByMachineName('colony_ship'), $this->random->getInt(1, 4) === 1 ? $this->logUniform(5) : 1);
        }
        foreach (array_slice($this->random->shuffleArray($this->flyingShips('colony_ship')), 0, $this->random->getInt(0, 2)) as $ship) {
            $units->addUnit($ship, $this->logUniform(100));
        }
        if ($units->getAmount() === 0) {
            $units->addUnit($this->pick($this->flyingShips('colony_ship')), 1);
        }
        $speed = $this->pick(self::SPEEDS);
        $capacity = $units->getTotalCargoCapacity($origin->getPlayer() ?? throw new Exception('No owner.'));
        $cargo = $this->cargo($capacity, 0.5);
        $fuel = $this->fuel($origin, $units, $target, 0, $speed);
        $this->stockOrigin($originId, $units, $cargo, $fuel, $perturbation, $start);
        $this->forget();
        $this->freshPlanet($originId)->update();

        $maxPlanets = $this->freshPlayer($owner)->getMaxPlanetAmount();
        $planetsBefore = $this->freshPlayer($owner)->planets->planetCount();
        $maxId = (int) Planet::query()->max('id');
        $seen = $this->messageMark();
        $input = $this->input($originId, null, $target, $targetType, $owner, null, $units, $cargo, $speed, 0);

        $taken = function () use ($perturbation, $other, $target): void {
            if ($perturbation !== 'taken_in_flight') {
                return;
            }
            app(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer($this->freshPlayer($other), $target);
            $this->forget();
        };

        return ['input' => $input + ['max_planets' => $maxPlanets]] + $this->fly(
            ColonisationMission::class,
            $originId,
            $target,
            $targetType,
            $units,
            $cargo,
            $speed,
            0,
            $caseSeed,
            during: $taken,
            after: function (FleetMission $sent) use ($owner, $maxId, $maxPlanets, $planetsBefore, $seen, $target, $caseSeed): array {
                $created = Planet::query()->where('id', '>', $maxId)->where('user_id', $owner)->first();
                $planetsAfter = $this->freshPlayer($owner)->planets->planetCount();
                $occupant = Planet::query()->where('galaxy', $target->galaxy)->where('system', $target->system)->where('planet', $target->position)->where('planet_type', 1)->value('user_id');
                $refusal = match (true) {
                    $created !== null => null,
                    $occupant !== null => 'slot_taken',
                    $planetsBefore + 1 > $maxPlanets => 'astrophysics',
                    default => 'cancelled',
                };

                return [
                    'planet_created' => $created !== null,
                    'refusal' => $refusal,
                    'max_planets' => $maxPlanets,
                    'planets_before' => $planetsBefore,
                    'planets_after' => $planetsAfter,
                    'new_planet' => $created === null ? null : $this->planetState((int) $created->id) + ['diameter' => $created->diameter, 'field_max' => $created->field_max, 'field_current' => $created->field_current, 'temp_min' => $created->temp_min, 'temp_max' => $created->temp_max, 'name' => $created->name],
                    'draws' => $created === null ? null : $this->colonyDraws($caseSeed, $target->position),
                    'messages' => $this->messagesSince($seen),
                ];
            },
        );
    }

    /** @return array<string, mixed> */
    private function colonyDraws(int $caseSeed, int $position): array
    {
        $stream = new Randomizer(new Mt19937($caseSeed));
        $data = app(PlanetServiceFactory::class)->planetData($position, false);

        return [
            'fields' => ['range' => $data['fields'], 'bonus' => app(SettingsService::class)->planetFieldsBonus(), 'draw' => $stream->getInt($data['fields'][0], $data['fields'][1])],
            'temperature' => ['range' => $data['temperature'], 'draw' => $stream->getInt($data['temperature'][0], $data['temperature'][1])],
        ];
    }

    // ------------------------------------------------------------- expedition

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function expedition(array $ids, int $start, int $caseSeed, string|null $perturbation): array
    {
        $owner = $this->pick($ids);
        $originId = $this->pick($this->freshPlayer($owner)->planets->allPlanets())->getPlanetId();
        $astrophysics = $perturbation === 'no_astrophysics' ? 0 : $this->random->getInt(1, 15);
        $this->drawPlayer($owner, ['astrophysics' => $astrophysics]);
        // The rank-1 score sets how much an expedition can find.
        $rank1 = [0, 5_000, 50_000, 500_000, 3_000_000, 10_000_000, 60_000_000, 200_000_000][$this->random->getInt(0, 7)];
        DB::table('highscores')->orderBy('id')->limit(1)->update(['general' => $rank1]);
        $this->forget();

        $origin = $this->freshPlanet($originId);
        $at = $origin->getPlanetCoordinates();
        $target = new Coordinate($at->galaxy, max(1, min(app(SettingsService::class)->numberOfSystems(), $at->system + $this->random->getInt(-30, 30))), 16);
        $targetType = $perturbation === 'not_deep_space' ? PlanetType::Planet : PlanetType::DeepSpace;
        if ($perturbation === 'not_deep_space') {
            $target = new Coordinate($target->galaxy, $target->system, $this->random->getInt(1, 15));
        }

        $units = new UnitCollection();
        $ships = $this->flyingShips();
        foreach (array_slice($this->random->shuffleArray($ships), 0, $this->random->getInt(1, 4)) as $ship) {
            $units->addUnit($ship, $this->logUniform(3000));
        }
        $player = $origin->getPlayer() ?? throw new Exception('No owner.');
        $hold = $this->random->getInt(1, max(1, $astrophysics));
        $speed = $this->pick(self::SPEEDS);
        $capacity = $units->getTotalCargoCapacity($player);
        $cargo = $this->cargo($capacity, $this->random->getInt(1, 4) === 1 ? 0.5 : 0.0);
        $fuel = $this->fuel($origin, $units, $target, $hold, $speed);
        $this->stockOrigin($originId, $units, $cargo, $fuel, $perturbation, $start);
        $this->forget();
        $this->freshPlanet($originId)->update();

        $seen = $this->messageMark();
        $userBefore = $this->userRow($owner);
        $input = $this->input($originId, null, $target, $targetType, $owner, null, $units, $cargo, $speed, $hold) + ['rank1_points' => $rank1];

        return ['input' => $input] + $this->fly(
            ExpeditionMission::class,
            $originId,
            $target,
            $targetType,
            $units,
            $cargo,
            $speed,
            $hold,
            $caseSeed,
            during: static function (): void {
            },
            after: function (FleetMission $sent) use ($owner, $caseSeed, $seen, $userBefore, $target, $units, $rank1): array {
                $messages = $this->messagesSince($seen);
                $back = FleetMission::query()->where('parent_id', $sent->id)->first();
                $returned = $back === null ? [0, 0, 0] : [(int) $back->metal, (int) $back->crystal, (int) $back->deuterium];
                $found = [$returned[0] - (int) $sent->metal, $returned[1] - (int) $sent->crystal, $returned[2] - (int) $sent->deuterium];
                $backUnits = $back === null ? [] : $this->counts(app()->makeWith(FleetMissionService::class, ['player' => $this->freshPlayer((int) $back->user_id)])->getFleetUnits($back));
                $foundShips = $backUnits;
                foreach ($this->counts($units) as $id => $amount) {
                    $foundShips[$id] = ($foundShips[$id] ?? 0) - $amount;
                }
                $decoded = $this->expeditionDraws($sent, $caseSeed, $rank1, $this->userRow($owner)['dark_matter'] - $userBefore['dark_matter'], $found, $units);

                return $decoded + [
                    'holding_seconds' => $sent->time_holding,
                    'found_resources' => $found,
                    'found_ships' => (object) array_filter($foundShips),
                    'rank1_points' => $rank1,
                    'user_changes' => $this->userChanges($userBefore, $this->userRow($owner)),
                    'debris_slot16' => $this->debris($target),
                    'return_delay' => $back === null ? null : ($back->time_arrival - $back->time_departure) - ($sent->time_arrival - $sent->time_departure),
                    'battle' => $this->expeditionBattle($messages),
                    'messages' => $messages,
                ];
            },
        );
    }

    /**
     * The draws in the host's call order (ExpeditionMission): the outcome roll over the scaled weights, then what the outcome
     * draws. A step the host skips is absent.
     *
     * @param  list<int>  $found
     * @return array<string, mixed>
     */
    private function expeditionDraws(FleetMission $sent, int $caseSeed, int $rank1, int $darkMatterGained, array $found, UnitCollection $sentUnits): array
    {
        $mission = app(GameMissionFactory::class)->getMissionById(15, ['fleetMissionService' => app()->makeWith(FleetMissionService::class, ['player' => $this->freshPlayer((int) $sent->user_id)]), 'messageService' => app(\OGame\Services\MessageService::class)]);
        $weightsMethod = new ReflectionMethod($mission, 'getOutcomeWeights');
        /** @var array<string, float> $weights */
        $weights = $weightsMethod->invoke($mission, $sent);
        $order = array_keys(array_filter($weights, static fn (float $weight): bool => $weight > 0));
        $total = (int) round(array_sum(array_filter($weights, static fn (float $weight): bool => $weight > 0)) * 10);

        $stream = new Randomizer(new Mt19937($caseSeed));
        $roll = $stream->getInt(1, $total);
        $outcome = $order[0];
        $running = 0;
        foreach ($order as $name) {
            $running += (int) round($weights[$name] * 10);
            if ($roll <= $running) {
                $outcome = $name;
                break;
            }
        }

        $draws = [];
        $check = null;
        $variant = function () use ($stream, &$draws): int {
            $pick = $stream->getInt(1, 100);
            $variant = $pick <= 89 ? 'normal' : ($pick <= 99 ? 'rare' : 'exceptional');
            $draws['variant_roll'] = $pick;
            $draws['variant'] = $variant;
            $multiplier = match ($variant) {
                'rare' => $stream->getInt(2, 3),
                'exceptional' => $stream->getInt(5, 10),
                default => 1,
            };
            $draws['multiplier'] = $multiplier;

            return $multiplier;
        };
        $baseFind = function () use ($stream, &$draws, $rank1): int {
            $max = 5_000_000;
            foreach (self::BRACKETS as [$below, $find]) {
                if ($rank1 < $below) {
                    $max = $find;
                    break;
                }
            }
            $min = max(1, (int) floor($max * 0.1));
            $draw = $stream->getInt($min, $max);
            $draws['base_find'] = ['range' => [$min, $max], 'draw' => $draw];

            return $draw;
        };
        switch ($outcome) {
            case 'resources':
                $multiplier = $variant();
                $base = $baseFind();
                $type = $stream->getInt(0, 2);
                $draws['resource_type'] = $type;
                $check = $this->resourcesCheck($sent, $base, $multiplier, $type, $found, $sentUnits);

                break;
            case 'ships':
                $variant();
                $baseFind();
                $draws['ship_types'] = $stream->getInt(1, 6);

                break;
            case 'dark_matter':
                $multiplier = $variant();
                $min = (int) app(SettingsService::class)->get('expedition_dark_matter_min_no_pathfinder', 150);
                $max = (int) app(SettingsService::class)->get('expedition_dark_matter_max_no_pathfinder', 200);
                $reward = $stream->getInt($min, $max);
                $draws['dm_reward'] = ['range' => [$min, $max], 'draw' => $reward];
                $check = (int) floor($reward * $multiplier) === $darkMatterGained;

                break;
            case 'delay':
                $pick = $stream->getInt(1, 100);
                $draws['delay_multiplier'] = $pick <= 89 ? 2 : ($pick <= 99 ? 3 : 5);
                $back = FleetMission::query()->where('parent_id', $sent->id)->first();
                $expected = intval($sent->time_holding * $draws['delay_multiplier'] / app(SettingsService::class)->fleetSpeedPeaceful());
                $check = $back !== null && ($back->time_arrival - $back->time_departure) - ($sent->time_arrival - $sent->time_departure) === $expected;

                break;
            case 'speedup':
                $draws['speedup_percent'] = $stream->getInt(5, 10);
                $back = FleetMission::query()->where('parent_id', $sent->id)->first();
                $expected = -intval((($sent->time_arrival - $sent->time_departure) + $sent->time_holding) * ($draws['speedup_percent'] / 100));
                $check = $back !== null && ($back->time_arrival - $back->time_departure) - ($sent->time_arrival - $sent->time_departure) === $expected;

                break;
            default:
        }

        return [
            'outcome_roll' => ['range' => $total, 'draw' => $roll],
            'weights' => $weights,
            'outcome_order' => $order,
            'outcome' => $outcome,
            'draws' => (object) $draws,
            'decode_check' => $check,
        ];
    }

    /**
     * The find the draws give: base draw, class and Pathfinder multipliers, reward setting and variant, capped by the free hold.
     *
     * @param  list<int>  $found
     */
    private function resourcesCheck(FleetMission $sent, int $baseDraw, int $multiplier, int $type, array $found, UnitCollection $units): bool
    {
        $player = $this->freshPlayer((int) $sent->user_id);
        $settings = app(SettingsService::class);
        $classMultiplier = app(\OGame\Services\CharacterClassService::class)->getExpeditionResourceMultiplier($player->getUser(), $settings->economySpeed());
        $pathfinder = $units->getAmountByMachineName('pathfinder') > 0 ? 2 : 1;
        $base = (int) ((int) ($baseDraw * $classMultiplier) * $pathfinder * $settings->expeditionRewardMultiplierResources());
        $max = (int) ($base * $multiplier);
        $free = $units->getTotalCargoCapacity($player) - (int) $sent->metal - (int) $sent->crystal - (int) $sent->deuterium;
        $expected = match ($type) {
            0 => [min($free, $max), 0, 0],
            1 => [0, (int) min($free, $max * (2 / 3)), 0],
            default => [0, 0, (int) min($free, $max * (1 / 3))],
        };

        return $expected === $found;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>|null
     */
    private function expeditionBattle(array $messages): array|null
    {
        $report = BattleReport::query()->orderByDesc('id')->first();
        $fought = array_filter($messages, static fn (array $message): bool => str_contains((string) $message['key'], 'battle'));
        if ($report === null || $fought === [] || !($report->general['expedition_battle'] ?? false)) {
            return null;
        }

        return ['npc_type' => $report->general['npc_type'] ?? null, 'report' => $this->battleReport($report)];
    }

    // ---------------------------------------------------------------- missile

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function missile(array $ids, int $start, int $caseSeed, string|null $perturbation): array
    {
        $owner = $this->pick($ids);
        $originId = $this->pick($this->freshPlayer($owner)->planets->allPlanets())->getPlanetId();
        $targetOwner = $perturbation === 'own_target' ? $owner : $this->pick(array_values(array_diff($ids, [$owner])));
        $targetPlanet = $perturbation === 'own_target' ? $this->freshPlanet($originId) : $this->pick($this->freshPlayer($targetOwner)->planets->allPlanets());
        $this->drawPlayer($owner, ['impulse_drive' => $this->random->getInt(0, 4)]);
        $this->drawPlayer($targetOwner);
        $this->forget();

        $targetId = $targetPlanet->getPlanetId();
        $type = PlanetType::Planet;
        if ($this->random->getInt(1, 10) <= 3) {
            $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($this->freshPlanet($targetId), 0, 0, $this->random->getInt(10, 20));
            $targetId = $moon->getPlanetId();
            $type = PlanetType::Moon;
            $this->forget();
        }
        $coordinate = $this->freshPlanet($targetId)->getPlanetCoordinates();

        $missiles = $this->logUniform(300);
        $this->stockMissileOrigin($originId, $missiles, $start);
        $this->stockMissileTarget($targetId, $start);
        if ($type === PlanetType::Moon) {
            // The parent planet's anti-ballistic missiles defend the moon first.
            DB::table('planets')->where('id', $targetPlanet->getPlanetId())->update(['anti_ballistic_missile' => $this->random->getInt(0, 1) === 1 ? $this->logUniform(100) : 0, 'time_last_update' => $start]);
        }
        $this->forget();
        $this->freshPlanet($originId)->update();
        $this->freshPlanet($targetId)->update();
        $this->forget();

        $sentCount = match ($perturbation) {
            'too_many_missiles' => $missiles + 1,
            'no_missiles' => 0,
            default => $this->random->getInt(1, 4) === 1 ? $missiles : $this->random->getInt(1, $missiles),
        };
        $priority = $this->random->getInt(0, 7);
        DB::table('users')->where('id', $owner)->update(['planet_current' => $originId]);
        $this->forget();

        $input = $this->input($originId, $targetId, $coordinate, $type, $owner, $targetOwner, new UnitCollection(), [0, 0, 0], 0.0, 0)
            + ['missiles' => $sentCount, 'priority' => $priority];
        $parentId = $type === PlanetType::Moon ? $targetPlanet->getPlanetId() : null;
        $abm = fn (): array => [(int) DB::table('planets')->where('id', $targetId)->value('anti_ballistic_missile'), $parentId === null ? 0 : (int) DB::table('planets')->where('id', $parentId)->value('anti_ballistic_missile')];
        $defenceBefore = $this->counts($this->freshPlanet($targetId)->getDefenseUnits());
        $abmBefore = $abm();
        $player = $this->freshPlayer($owner);
        $range = $player->getMissileRange();
        $distance = $this->freshPlanet($originId)->getPlanetCoordinates()->galaxy === $coordinate->galaxy ? abs($this->freshPlanet($originId)->getPlanetCoordinates()->system - $coordinate->system) : null;

        app()->instance(Randomizer::class, new Randomizer(new Mt19937($caseSeed)));
        $seen = $this->messageMark();
        $request = Request::create('/', 'POST', ['galaxy' => $coordinate->galaxy, 'system' => $coordinate->system, 'position' => $coordinate->position, 'type' => $type->value, 'missile_count' => $sentCount, 'target_priority' => $priority]);
        $response = app(GalaxyController::class)->missileAttack($request, $player, app(PlanetServiceFactory::class), app(SettingsService::class));
        $data = $response->getData(true);
        $this->forget();
        if (!($data['success'] ?? false)) {
            return ['input' => $input, 'dispatch' => ['accepted' => false, 'error' => (string) ($data['error'] ?? 'refused'), 'http_status' => $response->getStatusCode()], 'arrival' => null, 'return' => null];
        }

        $sent = FleetMission::query()->findOrFail((int) $data['mission_id']);
        $dispatch = ['accepted' => true, 'error' => null, 'http_status' => $response->getStatusCode(), 'departure' => $sent->time_departure, 'arrival' => $sent->time_arrival, 'fuel' => 0, 'holding_seconds' => 0, 'origin_after' => $this->planetState($originId)];

        SimulatedTime::freezeAt(Date::createFromTimestamp($sent->time_arrival));
        $targetBefore = $this->updatedState($targetId);
        $abmBeforeArrival = $abm();
        $attackerPlayer = $this->freshPlayer($owner);
        $defenderPlayer = $this->freshPlayer($targetOwner);
        $service = app()->makeWith(FleetMissionService::class, ['player' => $attackerPlayer]);
        $service->processDueMissionEventsForMissionId($sent->id);
        $this->forget();
        $abmAfter = $abm();
        $intercepted = ($abmBeforeArrival[0] + $abmBeforeArrival[1]) - ($abmAfter[0] + $abmAfter[1]);

        $arrival = [
            'time' => $sent->time_arrival,
            'target_before' => $targetBefore,
            'target_after' => $this->planetState($targetId),
            'debris_after' => $this->debris($coordinate),
            'return_mission' => null,
            'missiles_sent' => $sentCount,
            'priority' => $priority,
            'impulse_drive' => $attackerPlayer->getResearchLevel('impulse_drive'),
            'range' => $range,
            'distance' => $distance,
            'weapon_tech' => $attackerPlayer->getResearchLevel('weapon_technology'),
            'armour_tech' => $defenderPlayer->getResearchLevel('armor_technology'),
            'defence_before' => (object) $defenceBefore,
            'defence_after' => (object) $this->counts($this->freshPlanet($targetId)->getDefenseUnits()),
            'abm_before' => $abmBeforeArrival,
            'abm_after' => $abmAfter,
            'intercepted' => $intercepted,
            'effective' => $sentCount - $intercepted,
            'processed' => (bool) FleetMission::query()->whereKey($sent->id)->value('processed'),
            'messages' => $this->messagesSince($seen),
        ];

        return ['input' => $input, 'dispatch' => $dispatch, 'arrival' => $arrival, 'return' => null];
    }

    private function stockMissileOrigin(int $planetId, int $missiles, int $start): void
    {
        $columns = $this->zeroUnits();
        $columns['interplanetary_missile'] = $missiles;
        $columns['time_last_update'] = $start;
        DB::table('planets')->where('id', $planetId)->update($columns);
    }

    private function stockMissileTarget(int $planetId, int $start): void
    {
        $columns = $this->zeroUnits();
        foreach (array_slice($this->random->shuffleArray(ObjectService::getDefenseObjects()), 0, $this->random->getInt(0, 6)) as $defence) {
            $columns[$defence->machine_name] = $this->logUniform(300);
        }
        foreach (ObjectService::getShipObjects() as $ship) {
            if ($this->random->getInt(1, 12) === 1) {
                $columns[$ship->machine_name] = $this->logUniform(50);
            }
        }
        $columns['time_last_update'] = $start;
        DB::table('planets')->where('id', $planetId)->update($columns);
    }

    // ------------------------------------------------------ moon destruction

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function moonDestruction(array $ids, int $start, int $caseSeed, string|null $perturbation): array
    {
        $owner = $this->pick($ids);
        $originId = $this->pick($this->freshPlayer($owner)->planets->allPlanets())->getPlanetId();
        $targetOwner = $this->pick(array_values(array_diff($ids, [$owner])));
        $planet = $this->pick($this->freshPlayer($targetOwner)->planets->allPlanets());
        $this->drawPlayer($owner);
        $this->drawPlayer($targetOwner);
        $this->forget();

        $coordinate = $planet->getPlanetCoordinates();
        $moonId = null;
        if ($perturbation !== 'no_moon' && $perturbation !== 'target_is_planet') {
            $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($this->freshPlanet($planet->getPlanetId()), $this->logUniform(2_000_000), 20, $this->random->getInt(10, 20));
            $moonId = $moon->getPlanetId();
            if ($this->random->getInt(1, 10) <= 3) {
                DB::table('planets')->where('id', $moonId)->update(['diameter' => $this->random->getInt(1, 8944)]);
            }
            $this->forget();
            $this->stockMoon($moonId, $start);
        }
        $targetType = $perturbation === 'target_is_planet' ? PlanetType::Planet : PlanetType::Moon;

        $units = new UnitCollection();
        if ($perturbation !== 'no_deathstar') {
            $units->addUnit(ObjectService::getUnitObjectByMachineName('deathstar'), $this->random->getInt(1, 3) === 1 ? 1 : $this->logUniform(300));
        }
        $others = array_values(array_filter($this->flyingShips('deathstar'), static fn ($ship): bool => $ship->properties->speed->rawValue > 0));
        foreach (array_slice($this->random->shuffleArray($others), 0, $units->getAmount() === 0 ? 1 : $this->random->getInt(0, 2)) as $ship) {
            $units->addUnit($ship, $this->logUniform(200));
        }
        $origin = $this->freshPlanet($originId);
        $speed = $this->pick(self::SPEEDS);
        $fuel = $this->fuel($origin, $units, $coordinate, 0, $speed);
        $this->stockOrigin($originId, $units, [0, 0, 0], $fuel, $perturbation, $start);
        $this->forget();
        $this->freshPlanet($originId)->update();
        if ($moonId !== null) {
            $this->freshPlanet($moonId)->update();
        }
        $this->forget();

        $moonRow = $moonId === null ? null : Planet::query()->find($moonId);
        $deathstar = ObjectService::getUnitObjectByMachineName('deathstar')->id;
        $seen = $this->messageMark();
        $input = $this->input($originId, $moonId, $coordinate, $targetType, $owner, $targetOwner, $units, [0, 0, 0], $speed, 0);

        return ['input' => $input] + $this->fly(
            MoonDestructionMission::class,
            $originId,
            $coordinate,
            $targetType,
            $units,
            [0, 0, 0],
            $speed,
            0,
            $caseSeed,
            during: static function (): void {
            },
            after: function (FleetMission $sent) use ($moonId, $moonRow, $caseSeed, $seen, $deathstar, $coordinate): array {
                $messages = $this->messagesSince($seen);
                $report = BattleReport::query()->where('planet_type', 3)->orderByDesc('id')->first();
                $battle = $report === null || $moonId === null ? null : $this->battleReport($report);
                $keys = array_column($messages, 'key');
                $stream = new Randomizer(new Mt19937($caseSeed));
                $seed = $stream->getInt(0, PHP_INT_MAX);
                $destroyed = $moonId !== null && Planet::query()->whereKey($moonId)->doesntExist();
                $attempted = $battle !== null && array_filter($keys, static fn ($key): bool => str_starts_with((string) $key, 'moon_destr') || $key === 'moon_destroyed') !== [];
                $rolls = $attempted ? ['destroy' => $stream->getInt(1, 100), 'loss' => $stream->getInt(1, 100)] : null;
                $back = FleetMission::query()->where('parent_id', $sent->id)->first();
                $survivors = $battle === null ? 0 : (int) (((array) $battle['attacker_left'])[$deathstar] ?? 0);
                $diameter = $moonRow === null ? 0 : max(1, (int) $moonRow->diameter);
                $chance = ['destroy' => max(0, min(100, (100 - sqrt($diameter)) * sqrt($survivors))), 'loss' => max(0, min(100, sqrt($diameter) / 2))];
                $returned = $back === null ? 0 : (int) (($this->counts(app()->makeWith(FleetMissionService::class, ['player' => $this->freshPlayer((int) $back->user_id)])->getFleetUnits($back)))[$deathstar] ?? 0);

                return [
                    'moon_before' => $moonRow === null ? null : ['diameter' => $moonRow->diameter, 'fields' => $moonRow->field_max],
                    'moon_exists_after' => $moonId !== null && !$destroyed,
                    'battle' => $battle,
                    'seed' => $seed,
                    'rolls' => $rolls,
                    'attempted' => $attempted,
                    'chance' => $chance,
                    'deathstars_before' => $survivors,
                    'deathstars_after' => $returned,
                    'roll_consistent' => $rolls === null || ($destroyed === ($rolls['destroy'] <= $chance['destroy']) && ($destroyed || ($returned === 0) === ($rolls['loss'] <= $chance['loss']))),
                    'wreck_field' => $this->wreckField($coordinate),
                    'messages' => $messages,
                ];
            },
        );
    }

    // ------------------------------------------------------------- ACS defend

    /**
     * @param  list<int>  $ids  at least three
     * @return array<string, mixed>
     */
    private function acsDefend(array $ids, int $start, int $caseSeed, string|null $perturbation): array
    {
        [$owner, $mate, $raider] = array_slice($this->random->shuffleArray($ids), 0, 3);
        [$originId, $targetId, $raidId] = array_map(fn (int $id): int => $this->freshPlayer($id)->planets->allPlanets()[0]->getPlanetId(), [$owner, $mate, $raider]);
        foreach ([$owner, $mate, $raider] as $id) {
            $this->drawPlayer($id);
        }
        if ($perturbation !== 'not_mates') {
            BuddyRequest::query()->create(['sender_user_id' => $owner, 'receiver_user_id' => $mate, 'status' => BuddyRequest::STATUS_ACCEPTED]);
        }
        $this->forget();
        $this->stockMoon($targetId, $start);
        $coordinate = $this->freshPlanet($targetId)->getPlanetCoordinates();

        $units = $this->someShips();
        $origin = $this->freshPlanet($originId);
        $speed = $this->pick(self::SPEEDS);
        $hold = $this->random->getInt(0, 3);
        $cargo = $this->cargo($units->getTotalCargoCapacity($origin->getPlayer() ?? throw new Exception('No owner.')), 0.5);
        $this->stockOrigin($originId, $units, $cargo, $this->fuel($origin, $units, $coordinate, $hold, $speed), $perturbation, $start);
        $raid = $this->someShips();
        $raidSpeed = $this->pick(self::SPEEDS);
        $this->stockOrigin($raidId, $raid, [0, 0, 0], $this->fuel($this->freshPlanet($raidId), $raid, $coordinate, 0, $raidSpeed), null, $start);
        $this->forget();
        foreach ([$originId, $targetId, $raidId] as $planetId) {
            $this->freshPlanet($planetId)->update();
        }
        $this->forget();

        $input = $this->input($originId, $targetId, $coordinate, PlanetType::Planet, $owner, $mate, $units, $cargo, $speed, $hold)
            + ['raid' => ['origin' => $this->planetState($raidId), 'player' => $this->playerState($raider), 'units' => (object) $this->counts($raid), 'speed' => $raidSpeed]];
        $service = app()->makeWith(FleetMissionService::class, ['player' => $this->freshPlayer($owner)]);
        app()->instance(Randomizer::class, new Randomizer(new Mt19937($caseSeed)));
        try {
            $sent = $service->createNewFromPlanet($this->freshPlanet($originId), $coordinate, PlanetType::Planet, AcsDefendMission::getTypeId(), $units, new Resources($cargo[0], $cargo[1], $cargo[2], 0), $speed, $hold);
        } catch (Exception $refusal) {
            return ['input' => $input, 'dispatch' => ['accepted' => false, 'error' => $refusal->getMessage()], 'arrival' => null, 'return' => null];
        }
        $dispatch = ['accepted' => true, 'error' => null, 'departure' => $sent->time_departure, 'arrival' => $sent->time_arrival, 'fuel' => (int) $sent->deuterium_consumption, 'holding_seconds' => (int) $sent->time_holding, 'origin_after' => $this->planetState($originId)];
        $raidService = app()->makeWith(FleetMissionService::class, ['player' => $this->freshPlayer($raider)]);
        $attack = $raidService->createNewFromPlanet($this->freshPlanet($raidId), $coordinate, PlanetType::Planet, AttackMission::getTypeId(), $raid, new Resources(0, 0, 0, 0), $raidSpeed, 0);

        // Both left at the same second; the fight is processed at the attack's arrival, the hold's end after it.
        $hit = (int) $attack->time_arrival;
        SimulatedTime::freezeAt(Date::createFromTimestamp($hit));
        $targetBefore = $this->updatedState($targetId);
        $reports = (int) (BattleReport::query()->max('id') ?? 0);
        $raidService->processDueMissionEventsForMissionId($attack->id);
        $this->forget();
        $report = BattleReport::query()->where('id', '>', $reports)->orderBy('id')->first();
        $holding = FleetMission::query()->whereKey($sent->id)->where('processed', 0)->first();
        $raidBack = FleetMission::query()->where('parent_id', $attack->id)->first();
        $arrival = [
            'time' => $hit,
            'target_before' => $targetBefore,
            'target_after' => $this->planetState($targetId),
            'debris_after' => $this->debris($coordinate),
            'battle' => $report === null ? null : $this->battleReport($report),
            'defend_after' => $holding === null ? null : $this->flight($holding),
            'raid_return' => $raidBack === null ? null : $this->flight($raidBack),
        ];

        if (FleetMission::query()->where('parent_id', $sent->id)->doesntExist()) {
            SimulatedTime::freezeAt(Date::createFromTimestamp(max($hit, (int) $sent->time_arrival)));
            $service->processDueMissionEventsForMissionId($sent->id);
            $this->forget();
        }
        $back = FleetMission::query()->where('parent_id', $sent->id)->first();

        return [
            'input' => $input,
            'dispatch' => $dispatch,
            'arrival' => $arrival,
            'defend_return' => $back === null ? null : $this->flight($back),
            'return' => $back === null ? null : $this->comeHome($service, $back, $originId),
        ];
    }

    /** One to three kinds of flying ship, a few hundred at most of each. */
    private function someShips(): UnitCollection
    {
        $units = new UnitCollection();
        foreach (array_slice($this->random->shuffleArray($this->flyingShips()), 0, $this->random->getInt(1, 3)) as $ship) {
            $units->addUnit($ship, $this->logUniform(200));
        }

        return $units;
    }

    /** Ships and defences on the moon (or planet) half of the time, so the fight is not always a walkover. */
    private function stockMoon(int $moonId, int $start): void
    {
        $columns = $this->zeroUnits();
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $columns[$resource] = $this->logUniform(self::MAX_STOCK);
        }
        if ($this->random->getInt(0, 1) === 1) {
            foreach (array_slice($this->random->shuffleArray(ObjectService::getShipObjects()), 0, $this->random->getInt(0, 3)) as $ship) {
                $columns[$ship->machine_name] = $this->logUniform(100);
            }
            foreach (array_slice($this->random->shuffleArray(ObjectService::getDefenseObjects()), 0, $this->random->getInt(0, 3)) as $defence) {
                $columns[$defence->machine_name] = $this->logUniform(100);
            }
        }
        $columns['time_last_update'] = $start;
        DB::table('planets')->where('id', $moonId)->update($columns);
    }

    // ------------------------------------------------------------ the flight

    /**
     * Dispatch through the host, hand the world to `during` (a change between dispatch and arrival), process the arrival at
     * its second (holding time included) and the return at its own, and let `after` add what the mission is about.
     *
     * @param  class-string  $class
     * @param  list<int>  $cargo
     * @param  callable(): void  $during
     * @param  callable(FleetMission): array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function fly(string $class, int $originId, Coordinate $target, PlanetType $targetType, UnitCollection $units, array $cargo, float $speed, int $hold, int $caseSeed, callable $during, callable $after): array
    {
        $origin = $this->freshPlanet($originId);
        $player = $origin->getPlayer() ?? throw new Exception('The origin planet has no owner.');
        $service = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        app()->instance(Randomizer::class, new Randomizer(new Mt19937($caseSeed)));
        try {
            $sent = $service->createNewFromPlanet($origin, $target, $targetType, $class::getTypeId(), $units, new Resources($cargo[0], $cargo[1], $cargo[2], 0), $speed, $hold);
        } catch (Exception $refusal) {
            return ['dispatch' => ['accepted' => false, 'error' => $refusal->getMessage()], 'arrival' => null, 'return' => null];
        }
        $dispatch = ['accepted' => true, 'error' => null, 'departure' => $sent->time_departure, 'arrival' => $sent->time_arrival, 'fuel' => (int) $sent->deuterium_consumption, 'holding_seconds' => (int) $sent->time_holding, 'origin_after' => $this->planetState($originId)];

        $at = $sent->time_arrival + (int) $sent->time_holding;
        SimulatedTime::freezeAt(Date::createFromTimestamp($at));
        $during();
        $targetBefore = $sent->planet_id_to === null ? null : $this->updatedState($sent->planet_id_to);
        $service->processDueMissionEventsForMissionId($sent->id);
        $this->forget();

        $back = FleetMission::query()->where('parent_id', $sent->id)->first();
        $arrival = [
            'time' => $at,
            'target_before' => $targetBefore,
            'target_after' => $sent->planet_id_to === null ? null : $this->planetStateOrNull((int) $sent->planet_id_to),
            'debris_after' => $this->debris($target),
            'return_mission' => $back === null ? null : $this->flight($back),
        ] + $after($sent);

        return ['dispatch' => $dispatch, 'arrival' => $arrival, 'return' => $back === null ? null : $this->comeHome($service, $back, $originId)];
    }

    /** @return array<string, mixed> */
    private function comeHome(FleetMissionService $service, FleetMission $back, int $originId): array
    {
        SimulatedTime::freezeAt(Date::createFromTimestamp($back->time_arrival));
        $before = $this->updatedState($originId);
        $service->processDueMissionEventsForMissionId($back->id);
        $this->forget();

        return [
            'time' => $back->time_arrival,
            'origin_before' => $before,
            'origin_after' => $this->planetState($originId),
            'wreck_field' => null,
        ];
    }

    // --------------------------------------------------------------- the draw

    /**
     * @param  array<string, int>  $fixed  research levels to set instead of a random level
     */
    private function drawPlayer(int $id, array $fixed = []): void
    {
        $classes = array_values(array_filter(CharacterClass::cases(), static fn (CharacterClass $class): bool => $class !== CharacterClass::GENERAL));
        DB::table('users')->where('id', $id)->update(['character_class' => $this->pick($classes)->value, 'tactical_retreat_ratio' => 0]);
        $levels = [];
        foreach (ObjectService::getResearchObjects() as $research) {
            $levels[$research->machine_name] = $fixed[$research->machine_name] ?? $this->random->getInt(0, $this->maxLevel);
        }
        DB::table('users_tech')->where('user_id', $id)->update($levels);
    }

    /** @return list<\OGame\GameObjects\Models\ShipObject> ships that fly, minus one by name */
    private function flyingShips(string $without = ''): array
    {
        return array_values(array_filter(ObjectService::getShipObjects(), static fn ($ship): bool => $ship->properties->speed->rawValue > 0 && $ship->machine_name !== $without));
    }

    /** @return list<int> */
    private function cargo(int $capacity, float $share): array
    {
        $weights = [$this->random->getFloat(0, 1), $this->random->getFloat(0, 1), $this->random->getFloat(0, 1)];
        $sum = array_sum($weights) ?: 1.0;
        $share *= $this->random->getFloat(0, 1);

        return array_map(static fn (float $weight): int => (int) floor($capacity * $share * $weight / $sum), $weights);
    }

    private function fuel(PlanetService $origin, UnitCollection $units, Coordinate $target, int $hold, float $speed): int
    {
        $player = $origin->getPlayer() ?? throw new Exception('No owner.');

        return (int) app()->makeWith(FleetMissionService::class, ['player' => $player])->calculateConsumption($origin, $units, $target, $hold, $speed);
    }

    private function emptySlot(int $originPlanetId, int $lowest, int $highest): Coordinate
    {
        $origin = $this->freshPlanet($originPlanetId)->getPlanetCoordinates();
        $factory = app(PlanetServiceFactory::class);
        $systems = app(SettingsService::class)->numberOfSystems();
        do {
            $coordinate = new Coordinate($origin->galaxy, max(1, min($systems, $origin->system + $this->random->getInt(-5, 5))), $this->random->getInt($lowest, $highest));
        } while ($factory->planetExistsAtCoordinate($coordinate) || $this->debris($coordinate) !== null);

        return $coordinate;
    }

    /** @param list<int> $cargo */
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

    /** @return array<string, int> every unit column at zero */
    private function zeroUnits(): array
    {
        $columns = [];
        foreach (ObjectService::getUnitObjects() as $unit) {
            $columns[$unit->machine_name] = 0;
        }

        return $columns;
    }

    // -------------------------------------------------------------- recording

    /**
     * @param  list<int>  $cargo
     * @return array<string, mixed>
     */
    private function input(int $originId, int|null $targetId, Coordinate $target, PlanetType $type, int $owner, int|null $targetOwner, UnitCollection $units, array $cargo, float $speed, int $hold): array
    {
        return [
            'origin' => $this->planetState($originId),
            'target' => $targetId === null ? null : $this->planetState($targetId),
            'target_coordinates' => [$target->galaxy, $target->system, $target->position],
            'target_type' => $type->value,
            'origin_player' => $this->playerState($owner),
            'target_player' => $targetOwner === null ? null : $this->playerState($targetOwner),
            'units' => (object) $this->counts($units),
            'cargo' => $cargo,
            'speed' => $speed,
            'holding_hours' => $hold,
        ];
    }

    /** @return int the newest message id, to read what a mission wrote after it */
    private function messageMark(): int
    {
        return (int) (Message::query()->max('id') ?? 0);
    }

    /** @return list<array<string, mixed>> the flavour text's variation id is drawn outside the seeded stream, so it is left out */
    private function messagesSince(int $after): array
    {
        $messages = [];
        foreach (Message::query()->where('id', '>', $after)->orderBy('id')->get(['user_id', 'key', 'params']) as $message) {
            $messages[] = ['user' => $message->user_id, 'key' => $message->key, 'params' => array_diff_key((array) $message->params, ['message_variation_id' => 0])];
        }

        return $messages;
    }

    /** @return array<string, mixed> */
    private function userRow(int $id): array
    {
        return (array) DB::table('users')->where('id', $id)->first();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function userChanges(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $column => $value) {
            if ($column !== 'updated_at' && $before[$column] != $value) {
                $changes[$column] = [$before[$column], $value];
            }
        }

        return $changes;
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
            'moon_chance' => (int) ($report->general['moon_chance'] ?? 0),
            'moon_created' => (bool) ($report->general['moon_created'] ?? false),
            'repaired' => (object) $this->byId($report->repaired_defenses ?? []),
            'attacker' => $side($report->attacker ?? []),
            'defender' => $side($report->defender ?? []),
            'loot' => [(int) ($loot['metal'] ?? 0), (int) ($loot['crystal'] ?? 0), (int) ($loot['deuterium'] ?? 0)],
            'debris' => [(int) ($debris['metal'] ?? 0), (int) ($debris['crystal'] ?? 0), (int) ($debris['deuterium'] ?? 0)],
            'rounds' => count($rounds),
            'attacker_left' => (object) ($last === null ? $this->byId($report->attacker['units'] ?? []) : $this->byId($last['attacker_ships'] ?? [])),
            'defender_left' => (object) ($last === null ? $this->byId($report->defender['units'] ?? []) : $this->byId($last['defender_ships'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    private function flight(FleetMission $mission): array
    {
        $service = app()->makeWith(FleetMissionService::class, ['player' => $this->freshPlayer((int) $mission->user_id)]);

        return [
            'departure' => $mission->time_departure,
            'arrival' => $mission->time_arrival,
            'units' => (object) $this->counts($service->getFleetUnits($mission)),
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

    /** @return array<string, mixed>|null */
    private function planetStateOrNull(int $planetId): array|null
    {
        return Planet::query()->whereKey($planetId)->exists() ? $this->planetState($planetId) : null;
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
    private function wreckField(Coordinate $at): array|null
    {
        $field = \OGame\Models\WreckField::query()->where('galaxy', $at->galaxy)->where('system', $at->system)->where('planet', $at->position)->first();

        return $field === null ? null : ['owner' => $field->owner_player_id, 'status' => $field->status, 'ships' => $field->ship_data];
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
     * @return array<int, int> object id => value, zeros left out
     */
    private function byId(array $byName): array
    {
        $byId = [];
        foreach ($byName as $name => $value) {
            if ((int) $value !== 0) {
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

    private function logUniform(int $max): int
    {
        return max(1, min($max, (int) floor(exp($this->random->getFloat(0, log($max + 1))))));
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $values
     * @return T
     */
    private function pick(array $values): mixed
    {
        $values = array_values($values);

        return $values[$this->random->getInt(0, count($values) - 1)];
    }
}
