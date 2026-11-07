<?php

namespace Modules\AI\Domain\Choice;

use Modules\AI\Actions\QueueAiDefendAction;
use Modules\AI\Actions\QueueAiExpeditionAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableDefend;
use Modules\AI\Domain\Decision\QueueableDefendPlanner;
use Modules\AI\Domain\Decision\QueueableExpedition;
use Modules\AI\Domain\Decision\QueueableExpeditionPlanner;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Decision\QueueableJumpGate;
use Modules\AI\Domain\Decision\QueueableJumpGatePlanner;
use Modules\AI\Domain\Decision\QueueableMissile;
use Modules\AI\Domain\Decision\QueueableMissilePlanner;
use Modules\AI\Domain\Decision\QueueablePhalanx;
use Modules\AI\Domain\Decision\QueueablePhalanxPlanner;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\QueueableRecycle;
use Modules\AI\Domain\Decision\QueueableRecyclePlanner;
use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Login\LoginPlans;
use Modules\AI\Domain\Raid\ReportedPlanet;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Infrastructure\Battle\NativeRaidEstimator;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\AcsDefendMission;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\RustBattleEngine;
use OGame\GameMissions\BattleEngine\Services\LootService;
use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\ExpeditionMission;
use OGame\GameMissions\MoonDestructionMission;
use OGame\GameMissions\RecycleMission;
use OGame\GameObjects\Models\UnitObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\CharacterClassService;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;

/**
 * The errand choice in the simulator's wide layout (feature version 3, `ogsim/src/errands.rs`), so a model trained there
 * runs on the host. Each row is one of the engine's own candidates, encoded as the simulator encodes the errand of the
 * same kind; a candidate with no simulator kind (build, trade, officer...) is not a row, and waiting is row 0. Every
 * column is read from what the account sees: its own empire, its espionage reports (research as reported, else its own),
 * the galaxy view (debris, inactivity, a moon's diameter) and its battle messages. Roles are the host's (the espionage
 * and recycle missions' hulls, the catalogue's ship kinds and properties); no object is named here.
 */
class WideErrandEncoder
{
    public const VERSION = 3;

    public const MAX_ROWS = 64;

    public const STATE = [
        'stock', 'stock_ratio', 'probe', 'cargo', 'fighter', 'harvester', 'hull_home', 'hour_sin', 'hour_cos', 'hours_since_attack',
        'incoming_hours', 'idle', 'warship_value', 'has_moon', 'missiles', 'expedition_free', 'fleet_slots_free',
    ];

    public const CANDIDATE = [
        'distance', 'flight_hours', 'fuel_over_stock', 'expected_loot', 'loot_over_fuel', 'target_ratio_observed_or_estimated',
        'report_age_hours', 'target_inactive', 'field', 'field_over_capacity', 'target_stock', 'attack_in_hours', 'order_share',
    ];

    public const WIDE_CANDIDATE = [
        'fleet_value', 'odds', 'predicted_loss', 'loss_share', 'predicted_win', 'speed', 'doubler', 'escort', 'expedition_free',
        'unit_attack_per_price', 'unit_hull_per_price', 'unit_shield_per_price', 'unit_cargo_per_price', 'unit_speed',
        'unit_military', 'unit_civil', 'unit_defence', 'order_over_stock',
    ];

    /** The simulator's kinds in one-hot order; the first seven are the narrow menu's. */
    public const KINDS = [
        'spy', 'raid', 'recycle', 'save', 'recall', 'order', 'wait',
        'strike', 'moon_destroy', 'moon_shot', 'acs_join', 'acs_defend', 'expedition', 'deploy', 'gate_save', 'phalanx',
    ];

    private const NARROW_KINDS = 7;

    private const WEIGHTS = [1.0, 1.5, 2.0];

    private const FULL_SPEED = 10.0;

    /** @var array<int, PlayerService> the report's owner as the report pictures them, per report */
    private array $pictured = [];

    public function __construct(
        private PlanetServiceFactory $planets,
        private SettingsService $settings,
    ) {
    }

    /** @return list<string> every candidate column, as `errands::cand_columns(true)` writes them */
    public static function candidateNames(): array
    {
        return [...self::CANDIDATE, ...array_slice(self::KINDS, 0, self::NARROW_KINDS), ...self::WIDE_CANDIDATE, ...array_slice(self::KINDS, self::NARROW_KINDS)];
    }

    /**
     * The choice point: row 0 waits, each further row is an engine candidate whose type is a simulator kind (its index
     * in the trace is the row's `objectId`), and the teacher row is the engine's own selection, or waiting.
     */
    public function point(int $playerId, PlayerService $player, DecisionTrace $trace, string $key): ChoicePoint
    {
        $this->pictured = [];
        $capital = $player->planets->first() ?? $player->planets->current();
        $fleets = app()->makeWith(FleetMissionService::class, ['player' => $player]);
        $context = ['player' => $player, 'capital' => $capital, 'fleets' => $fleets, 'inbound' => $trace->perception->inboundFleets, 'absence' => $trace->perception->upcomingAbsenceMinutes];

        $candidates = [app()->makeWith(ChoiceCandidate::class, ['objectId' => null, 'pass' => null, 'reason' => 'wait', 'legal' => true, 'features' => $this->row('wait', null, $context)])];
        $teacher = 0;
        $raids = [];
        // Raids first, so the phalanx row finds the raid it times whatever order the engine ranked them in.
        $order = array_keys($trace->candidates);
        usort($order, static fn (int $a, int $b): int => ($trace->candidates[$a]->candidate->type !== AiCandidateActionType::Raid) <=> ($trace->candidates[$b]->candidate->type !== AiCandidateActionType::Raid));
        foreach ($order as $index) {
            $scored = $trace->candidates[$index];
            $row = $this->candidateRow($scored, $context, $raids);
            if ($row === null || count($candidates) >= self::MAX_ROWS) {
                continue;
            }
            $candidates[] = app()->makeWith(ChoiceCandidate::class, ['objectId' => $index, 'pass' => null, 'reason' => $row['kind'], 'legal' => true, 'features' => $row['features']]);
            $teacher = $scored === $trace->selected ? count($candidates) - 1 : $teacher;
        }

        return app()->makeWith(ChoicePoint::class, [
            'playerId' => $playerId,
            'planetId' => $capital->getPlanetId(),
            'kind' => ChoicePoint::ERRAND,
            'state' => $this->state($context),
            'candidates' => $candidates,
            'teacherIndex' => $teacher,
            'key' => $key,
        ]);
    }

    /**
     * The account's columns (`errands::account_features`), all read on the capital as the simulator's account is.
     *
     * @param array<string, mixed> $context
     * @return list<float>
     */
    public function state(array $context): array
    {
        /** @var PlayerService $player */
        $player = $context['player'];
        /** @var PlanetService $capital */
        $capital = $context['capital'];
        $resources = $capital->getResources();
        $stock = $this->weighted($resources);
        $capacity = $capital->metalStorage()->get() + $capital->crystalStorage()->get() + $capital->deuteriumStorage()->get();
        $roles = $this->roles($player, $capital);
        $shelf = $capital->getShipUnits();
        $hour = ($context['now'] ?? now()->secondsSinceMidnight()) / 86_400 * 2 * M_PI;
        $incoming = $this->incomingHours($context, $capital);
        $missile = ObjectService::getUnitObjectByMachineName(QueueableMissilePlanner::MISSILE);
        $slots = $player->getFleetSlotsMax();
        $expeditions = $player->getExpeditionSlotsMax();

        return [
            $this->ln1p($stock),
            $this->ratio($stock, $capacity),
            ...array_map(fn (UnitObject|null $unit): float => $this->ln1p($unit === null ? 0 : $shelf->getAmountByMachineName($unit->machine_name)), $roles),
            $this->ln1p($this->strength($player, DefenderFleet::fromPlanet($capital)->units)[1]),
            sin($hour),
            cos($hour),
            $this->ln1p($this->hoursSinceAttack($player, $capital)),
            $incoming,
            $capital->isBuilding() ? 1.0 : 0.0,
            $this->ln1p($this->value($this->warships($player, $shelf))),
            $capital->hasMoon() ? 1.0 : 0.0,
            $this->ln1p($capital->getObjectAmount($missile->machine_name)),
            $expeditions > 0 ? $this->ratio(max(0, $expeditions - $player->getExpeditionSlotsInUse()), $expeditions) : 0.0,
            $slots > 0 ? $this->ratio(max(0, $slots - $player->getFleetSlotsInUse()), $slots) : 0.0,
        ];
    }

    /**
     * The row of an order of this unit (the scripted rule's whole want, `Size::Want`): its share and the unit's columns.
     *
     * @return list<float>
     */
    public function orderRow(UnitObject $unit, PlayerService $player): array
    {
        return $this->row('order', ['kind' => 'order', 'unit' => $unit], ['player' => $player]);
    }

    /**
     * One engine candidate as a simulator row, or null when its type has no simulator kind or its plan is gone.
     *
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $raids raid trips by target planet id, for the phalanx row that times one
     * @return array{kind: string, features: list<float>}|null
     */
    private function candidateRow(ScoredCandidate $scored, array $context, array &$raids): array|null
    {
        $player = $context['player'];
        $playerId = $player->getId();
        // The engine just asked these planners the same questions on the same state; its answers are reused (LoginPlans).
        $plans = app(LoginPlans::class);
        $reportId = (int) ($scored->candidate->parameters['report_id'] ?? 0);
        $trip = match ($scored->candidate->type) {
            AiCandidateActionType::Spy => $this->spyTrip($plans->reuse(QueueableSpyPlanner::class, [$playerId], fn () => app(QueueableSpyPlanner::class)->plan($playerId, $player)), $player),
            AiCandidateActionType::Raid => $this->raidTrip($plans->reuse(RaidPlanner::class, [$playerId, $reportId], fn () => app(RaidPlanner::class)->plan($playerId, $reportId)), $player),
            AiCandidateActionType::Recycle => $this->recycleTrip($plans->reuse(QueueableRecyclePlanner::class, [$playerId], fn () => app(QueueableRecyclePlanner::class)->plan($playerId)), $player),
            AiCandidateActionType::FleetSave => $this->saveTrip($context, $player),
            AiCandidateActionType::Recall => ['kind' => 'recall', 'origin' => $context['capital'], 'to' => $context['capital']->getPlanetCoordinates()],
            AiCandidateActionType::QueueUnits => $this->orderTrip($plans->reuse(QueueableUnitPlanner::class, [$playerId], fn () => app(QueueableUnitPlanner::class)->plan($playerId, $player))),
            AiCandidateActionType::Missile => $this->strikeTrip($plans->reuse(QueueableMissilePlanner::class, [$playerId], fn () => app(QueueableMissilePlanner::class)->plan($playerId)), $player),
            AiCandidateActionType::Defend => $this->defendTrip($plans->reuse(QueueableDefendPlanner::class, [$playerId], fn () => app(QueueableDefendPlanner::class)->plan($playerId)), $player),
            AiCandidateActionType::Expedition => $this->expeditionTrip($plans->reuse(QueueableExpeditionPlanner::class, [$playerId], fn () => app(QueueableExpeditionPlanner::class)->plan($playerId)), $player),
            AiCandidateActionType::JumpGate => $this->gateTrip($plans->reuse(QueueableJumpGatePlanner::class, [$playerId], fn () => app(QueueableJumpGatePlanner::class)->plan($playerId)), $context),
            AiCandidateActionType::Phalanx => $this->phalanxTrip($plans->reuse(QueueablePhalanxPlanner::class, [$playerId], fn () => app(QueueablePhalanxPlanner::class)->plan($playerId, $player)), $context, $raids),
            default => null,
        };
        if ($trip === null) {
            return null;
        }
        if (in_array($trip['kind'], ['raid', 'moon_destroy'], true) && isset($trip['target_id'])) {
            $raids[(int) $trip['target_id']] = $trip;
        }

        return ['kind' => $trip['kind'], 'features' => $this->row($trip['kind'], $trip, $context)];
    }

    /**
     * `errands::candidate_features_in`: the kind's one-hot, `CAND_NAMES` from the trip, then the wide columns.
     *
     * @param array<string, mixed>|null $trip
     * @param array<string, mixed> $context
     * @return list<float>
     */
    private function row(string $kind, array|null $trip, array $context): array
    {
        $narrow = array_fill(0, count(self::CANDIDATE), 0.0);
        $wide = array_fill(0, count(self::WIDE_CANDIDATE), 0.0);
        $kinds = array_fill(0, count(self::KINDS), 0.0);
        $kinds[array_search($kind, self::KINDS, true)] = 1.0;
        if ($trip !== null) {
            $narrow = $this->narrow($kind, $trip, $context, $narrow);
            $wide = $this->wide($kind, $trip, $context, $wide);
        }

        return [...array_values($narrow), ...array_slice($kinds, 0, self::NARROW_KINDS), ...array_values($wide), ...array_slice($kinds, self::NARROW_KINDS)];
    }

    /**
     * `errands::narrow_features`. A row that flies nothing a feature can price (a recall, missiles, a gate jump) carries
     * only its distance, as there.
     *
     * @param array<string, mixed> $trip
     * @param array<string, mixed> $context
     * @param array<int, float> $f
     * @return array<int, float>
     */
    private function narrow(string $kind, array $trip, array $context, array $f): array
    {
        if ($kind === 'order') {
            $f[12] = 1.0;

            return $f;
        }

        /** @var PlayerService $player */
        $player = $context['player'];
        /** @var FleetMissionService $fleets */
        $fleets = $context['fleets'];
        /** @var PlanetService $origin */
        $origin = $trip['origin'];
        /** @var Coordinate $to */
        $to = $trip['to'];
        $f[0] = $fleets->calculateFleetMissionDistance($origin, $to) / 100;
        /** @var UnitCollection|null $units */
        $units = $trip['units'] ?? null;
        if ($units === null || $units->getAmount() < 1 || in_array($kind, ['strike', 'gate_save', 'recall'], true)) {
            return $f;
        }

        $speed = (float) ($trip['speed'] ?? self::FULL_SPEED);
        $flight = $fleets->calculateFleetMissionDuration($origin, $to, $units, GameMissionFactory::getMissionById((int) $trip['mission'], []), $speed);
        $fuel = (float) $fleets->calculateConsumption($origin, $units, $to, 0, $speed);
        $loot = $this->weighted($trip['loot'] ?? new Resources());
        $f[1] = $flight / 3600;
        $f[2] = $fuel / max(1.0, $origin->deuterium()->get());
        $f[3] = $this->ln1p($loot);
        $f[4] = $fuel > 0 ? $loot / (self::WEIGHTS[2] * $fuel) : 0.0;

        // The simulator's target slot: a moon strike names the planet (its fight reads the moon's report, `wide`).
        $type = $kind === 'moon_destroy' ? PlanetType::Planet->value : (int) ($trip['type'] ?? PlanetType::Planet->value);
        $report = $this->lastReport($player->getId(), $to, $type);
        if ($report !== null) {
            $guns = $this->reportUnits($report->ships ?? []);
            $attack = $this->strength($this->picture($report, $player), $guns)[0];
            $hull = max(1.0, $this->strength($player, DefenderFleet::fromPlanet($context['capital'])->units)[1]);
            $f[5] = log(max(1e-9, $attack * 3 / $hull));
            $f[6] = max(0, now()->getTimestamp() - $report->created_at?->getTimestamp()) / 3600;
            $f[10] = $this->ln1p($this->weighted($this->resources($report->resources ?? [])));
        }
        $f[7] = $this->targetInactive($to, $type) ? 1.0 : 0.0;
        // The simulator keeps a field over the planet's slot, never its moon's.
        [$f[8], $f[9]] = $type === PlanetType::Moon->value ? [0.0, 0.0] : $this->field($player, $context['capital'], $to, $fleets);
        if ($kind === 'save') {
            $f[11] = $this->incomingHours($context, $context['capital']);
        }

        return $f;
    }

    /**
     * `errands::wide_features`: the fleet's value and speed, against the last report its odds and the host engine's
     * forecast (a moon strike's win and loss being the host's destruction and loss chances), a salvo's value, an
     * expedition's riders, an order's unit.
     *
     * @param array<string, mixed> $trip
     * @param array<string, mixed> $context
     * @param array<int, float> $f
     * @return array<int, float>
     */
    private function wide(string $kind, array $trip, array $context, array $f): array
    {
        /** @var PlayerService $player */
        $player = $context['player'];
        /** @var UnitCollection|null $units */
        $units = $trip['units'] ?? null;
        $flies = $units !== null && $units->getAmount() > 0 && !in_array($kind, ['strike', 'gate_save', 'recall'], true);
        if ($flies) {
            $value = $this->value($units);
            $f[0] = $this->ln1p($value);
            $f[5] = (float) ($trip['speed'] ?? self::FULL_SPEED) / self::FULL_SPEED;
            $f = in_array($kind, ['raid', 'phalanx', 'moon_destroy'], true) ? $this->fight($kind, $trip, $player, $value, $f) : $f;
        }
        if ($kind === 'strike' && isset($trip['report']) && $units !== null) {
            $defended = $this->reportedValue($trip['report']);
            $salvo = $this->value($units);
            $f[0] = $defended === null ? 0.0 : $this->ln1p($salvo);
            $f[1] = $defended !== null && $defended > 0 ? log(max(1.0, $salvo) / $defended) : 0.0;
        }
        if ($kind === 'expedition') {
            $f[6] = $trip['doubler'] ? 1.0 : 0.0;
            $f[7] = $trip['escort'] ? 1.0 : 0.0;
            $slots = $player->getExpeditionSlotsMax();
            $f[8] = $slots > 0 ? $this->ratio(max(0, $slots - $player->getExpeditionSlotsInUse()), $slots) : 0.0;
        }
        if ($kind === 'order' && isset($trip['unit'])) {
            $f = $this->unitColumns($trip['unit'], $player, $f);
        }

        return $f;
    }

    /**
     * @param array<string, mixed> $trip
     * @param array<int, float> $f
     * @return array<int, float>
     */
    private function fight(string $kind, array $trip, PlayerService $player, float $value, array $f): array
    {
        /** @var EspionageReport|null $report */
        $report = $trip['report'] ?? null;
        if ($report === null) {
            return $f;
        }
        $defended = $this->reportedValue($report);
        if ($defended !== null && $defended > 0) {
            $f[1] = log(max($value, 1.0) / $defended);
        }
        $forecast = $this->forecast($player, $trip['units'], $report);
        if ($forecast !== null) {
            $f[2] = $this->ln1p($forecast[0]);
            $f[3] = $this->ratio($forecast[0], $value);
            $f[4] = $forecast[1];
        }
        if ($kind === 'moon_destroy' && isset($trip['diameter'])) {
            [$destroy, $loss] = $this->moonChances((int) $trip['diameter'], (int) $trip['destroyers']);
            $f[3] = $loss / 100;
            $f[4] = $destroy / 100;
        }

        return $f;
    }

    /**
     * `players::forecast`: the host's engine on the fleet against the report alone (its counts, its research or else our
     * own), both sides scaled down to about 200 units, as (our loss at host prices, 1 win, 0.5 draw, 0 loss).
     *
     * @return array{0: float, 1: float}|null
     */
    private function forecast(PlayerService $player, UnitCollection $fleet, EspionageReport $report): array|null
    {
        $pictured = $this->picture($report, $player);
        $seen = app(ReportedPlanet::class)->of($report, $pictured);
        if ($seen === null) {
            return null;
        }
        $defending = DefenderFleet::fromPlanet($seen)->units;
        [$shrunkFleet, $shrunkDefending, $scale] = app(NativeRaidEstimator::class)->shrink($fleet, $defending);

        $attacker = new AttackerFleet();
        $attacker->units = $shrunkFleet;
        $attacker->player = $player;
        $attacker->fleetMissionId = 0;
        $attacker->ownerId = $player->getId();
        $attacker->cargoResources = new Resources();
        $attacker->isInitiator = true;
        $attacker->fleetMission = null;

        $defender = new DefenderFleet();
        $defender->units = $shrunkDefending;
        $defender->player = $pictured;
        $defender->fleetMissionId = 0;
        $defender->ownerId = (int) $report->planet_user_id;
        $defender->fleetMission = null;

        $result = (new RustBattleEngine([$attacker], $seen, [$defender], $this->settings))->simulateBattle(1, true);
        $lost = $this->value($result->attackerUnitsLost) * $scale;
        $win = match (true) {
            $result->attackerUnitsResult->getAmount() < 1 => 0.0,
            $result->defenderUnitsResult->getAmount() < 1 => 1.0,
            default => 0.5,
        };

        return [$lost, $win];
    }

    /** @return array{0: float, 1: float} the host's moon destruction and fleet loss chances in percent (WIK-196) */
    private function moonChances(int $diameter, int $destroyers): array
    {
        // MoonDestructionMission keeps both private; the same two lines, read on the diameter the galaxy view shows.
        $diameter = max(1, $diameter);

        return [max(0.0, min(100.0, (100 - sqrt($diameter)) * sqrt(max(0, $destroyers)))), max(0.0, min(100.0, sqrt($diameter) / 2))];
    }

    /**
     * @param array<int, float> $f
     * @return array<int, float>
     */
    private function unitColumns(UnitObject $unit, PlayerService $player, array $f): array
    {
        $price = max(1.0, $this->weighted($unit->price->resources));
        $per = fn (float $x): float => $this->ln1p(1000 * $x / $price);
        $f[9] = $per($unit->properties->attack->calculate($player)->totalValue);
        $f[10] = $per(intdiv($unit->properties->structural_integrity->calculate($player)->totalValue, 10));
        $f[11] = $per($unit->properties->shield->calculate($player)->totalValue);
        $f[12] = $per($unit->properties->capacity->calculate($player)->totalValue);
        $f[13] = $this->ln1p($unit->properties->speed->calculate($player)->totalValue);
        $f[14] = $this->listed($unit, ObjectService::getMilitaryShipObjects()) ? 1.0 : 0.0;
        $f[15] = $this->listed($unit, ObjectService::getCivilShipObjects()) ? 1.0 : 0.0;
        $f[16] = $this->listed($unit, ObjectService::getDefenseObjects()) ? 1.0 : 0.0;

        return $f;
    }

    /** @return array<string, mixed>|null */
    private function spyTrip(QueueableSpy|null $plan, PlayerService $player): array|null
    {
        if ($plan === null) {
            return null;
        }
        // The simulator prices a spy as one probe, whatever the volley.
        $units = new UnitCollection();
        $units->addUnit(ObjectService::getUnitObjectByMachineName(EspionageMission::getRequiredShipMachineNames()[0]), 1);

        return ['kind' => 'spy', 'origin' => $this->planets->makeForPlayer($player, $plan->planetId, false), 'to' => new Coordinate($plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition),
            'type' => $plan->targetType, 'units' => $units, 'mission' => EspionageMission::getTypeId()];
    }

    /** @return array<string, mixed>|null */
    private function raidTrip(QueueableRaid|null $plan, PlayerService $player): array|null
    {
        if ($plan === null) {
            return null;
        }
        $origin = $this->planets->makeForPlayer($player, $plan->originPlanetId, false);
        $units = $plan->launchUnits === [] ? $origin->getShipUnits() : $this->collection($plan->launchUnits);
        $to = new Coordinate($plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition);
        $report = $this->lastReport($player->getId(), $to, $plan->targetType);
        $target = Planet::query()->where('galaxy', $to->galaxy)->where('system', $to->system)->where('planet', $to->position)->where('planet_type', $plan->targetType)->first();
        $fraction = app(CharacterClassService::class)->getInactiveLootPercentage($player->getUser());
        $stock = $this->resources($report->resources ?? []);
        $loot = LootService::distributeLoot(new Resources($stock->metal->get() * $fraction, $stock->crystal->get() * $fraction, $stock->deuterium->get() * $fraction, 0), $units->getTotalCargoCapacity($player));
        $destroy = $plan->missionType === MoonDestructionMission::getTypeId();

        return ['kind' => $destroy ? 'moon_destroy' : 'raid', 'origin' => $origin, 'to' => $to, 'type' => $plan->targetType, 'units' => $units, 'mission' => $plan->missionType,
            'loot' => $loot, 'report' => $report, 'target_id' => $target?->id, 'diameter' => $destroy ? $target?->diameter : null,
            'destroyers' => array_sum(array_map(static fn (string $name): int => $units->getAmountByMachineName($name), MoonDestructionMission::getRequiredShipMachineNames()))];
    }

    /** @return array<string, mixed>|null */
    private function recycleTrip(QueueableRecycle|null $plan, PlayerService $player): array|null
    {
        if ($plan === null) {
            return null;
        }
        $origin = $this->planets->makeForPlayer($player, $plan->planetId, false);
        $harvester = ObjectService::getUnitObjectByMachineName(RecycleMission::getHarvesterMachineNameForPosition($plan->targetPosition));
        $hold = max(1, $harvester->properties->capacity->calculate($player)->totalValue);
        $units = new UnitCollection();
        $count = min((int) ceil($plan->mass / $hold), $origin->getObjectAmount($harvester->machine_name));
        if ($count > 0) {
            $units->addUnit($harvester, $count);
        }

        return ['kind' => 'recycle', 'origin' => $origin, 'to' => new Coordinate($plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition), 'type' => $plan->targetType,
            'units' => $units, 'mission' => RecycleMission::getTypeId()];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function saveTrip(array $context, PlayerService $player): array|null
    {
        $minutes = $context['absence'] ?? null;
        $planner = app(QueueableFleetSavePlanner::class);
        $plans = app(LoginPlans::class);
        $plan = is_int($minutes)
            ? $plans->reuse(QueueableFleetSavePlanner::class . '::proactivePlan', [$player->getId(), $minutes], fn () => $planner->proactivePlan($player->getId(), $minutes))
            : $plans->reuse(QueueableFleetSavePlanner::class, [$player->getId()], fn () => $planner->plan($player->getId(), $player));
        if (!$plan instanceof QueueableFleetSave) {
            return null;
        }
        $origin = $this->planets->makeForPlayer($player, $plan->originPlanetId, false);
        $to = $plan->destinationPlanetId > 0
            ? $this->planets->make($plan->destinationPlanetId, true)?->getPlanetCoordinates()
            : new Coordinate($plan->harvestGalaxy, $plan->harvestSystem, $plan->harvestPosition);

        // The simulator prices every save at full speed (`errands::flown`), whatever speed the save then flies at.
        return $to === null ? null : ['kind' => 'save', 'origin' => $origin, 'to' => $to, 'units' => $this->movable($player, $origin->getShipUnits()),
            'mission' => $plan->missionType, 'speed' => self::FULL_SPEED];
    }

    /** @return array<string, mixed>|null */
    private function orderTrip(QueueableUnit|null $plan): array|null
    {
        return $plan === null ? null : ['kind' => 'order', 'unit' => ObjectService::getUnitObjectById($plan->unitId)];
    }

    /** @return array<string, mixed>|null */
    private function strikeTrip(QueueableMissile|null $plan, PlayerService $player): array|null
    {
        if ($plan === null) {
            return null;
        }
        $to = new Coordinate($plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition);
        $origin = $this->planets->makeForPlayer($player, $plan->originPlanetId, false);

        return ['kind' => 'strike', 'origin' => $origin, 'to' => $to, 'type' => $plan->targetType,
            'units' => $this->collection([QueueableMissilePlanner::MISSILE => $origin->getObjectAmount(QueueableMissilePlanner::MISSILE)]),
            'report' => $this->lastReport($player->getId(), $to, $plan->targetType)];
    }

    /** @return array<string, mixed>|null */
    private function defendTrip(QueueableDefend|null $plan, PlayerService $player): array|null
    {
        $target = $plan === null ? null : $this->planets->make($plan->targetPlanetId, true);
        if ($plan === null || $target === null) {
            return null;
        }
        $origin = $this->planets->makeForPlayer($player, $plan->sourcePlanetId, false);

        return ['kind' => 'acs_defend', 'origin' => $origin, 'to' => $target->getPlanetCoordinates(), 'type' => $target->getPlanetType()->value,
            'units' => app(QueueAiDefendAction::class)->heldFleet($origin), 'mission' => AcsDefendMission::getTypeId()];
    }

    /** @return array<string, mixed>|null */
    private function expeditionTrip(QueueableExpedition|null $plan, PlayerService $player): array|null
    {
        if ($plan === null) {
            return null;
        }
        $origin = $this->planets->makeForPlayer($player, $plan->planetId, false);
        $units = app(QueueAiExpeditionAction::class)->disposableFleet($player, $origin) ?? new UnitCollection();
        $planner = app(QueueableExpeditionPlanner::class);

        // The simulator's doubler is the fast civil hull that doubles a find; its escort the strongest hull riding along. It
        // prices an expedition at the account's own slot (no target: distance, flight, fuel and field are its own planet's).
        return ['kind' => 'expedition', 'origin' => $origin, 'to' => $origin->getPlanetCoordinates(), 'units' => $units,
            'mission' => ExpeditionMission::getTypeId(), 'doubler' => $planner->fastestCivilHull($player, $origin) !== null, 'escort' => $planner->combatHull($player, $origin) !== null];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function gateTrip(QueueableJumpGate|null $plan, array $context): array|null
    {
        $moon = $plan === null ? null : $this->planets->make($plan->sourceMoonId, true);

        return $moon === null ? null : ['kind' => 'gate_save', 'origin' => $context['capital'], 'to' => $moon->getPlanetCoordinates()];
    }

    /**
     * The scan is the simulator's phalanx errand only together with the raid it times: the raid on the scanned planet
     * this login offers, else the distance alone.
     *
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $raids
     * @return array<string, mixed>|null
     */
    private function phalanxTrip(QueueablePhalanx|null $plan, array $context, array $raids): array|null
    {
        $target = $plan === null ? null : $this->planets->make($plan->targetPlanetId, true);
        if ($plan === null || $target === null) {
            return null;
        }

        return ['kind' => 'phalanx'] + ($raids[$plan->targetPlanetId] ?? ['origin' => $context['capital'], 'to' => $target->getPlanetCoordinates()]);
    }

    /**
     * The roles `players::Kit` reads on the account: the espionage mission's hull, the best cargo hull per price, the
     * cheapest shooter held or buildable (else any), the recycle mission's hull.
     *
     * @return list<UnitObject|null> probe, cargo, fighter, harvester
     */
    private function roles(PlayerService $player, PlanetService $capital): array
    {
        $probe = ObjectService::getUnitObjectByMachineName(EspionageMission::getRequiredShipMachineNames()[0]);
        $harvester = ObjectService::getUnitObjectByMachineName(RecycleMission::getHarvesterMachineNameForPosition(1));
        $flying = array_values(array_filter(ObjectService::getShipObjects(), fn (UnitObject $unit): bool => $unit->properties->speed->calculate($player)->totalValue > 0
            && $unit->machine_name !== $probe->machine_name && $unit->machine_name !== $harvester->machine_name));
        $cargo = $this->best($flying, fn (UnitObject $unit): float => $unit->properties->capacity->calculate($player)->totalValue / max(1.0, $this->sum($unit->price->resources)), true);
        $shooters = array_values(array_filter($flying, fn (UnitObject $unit): bool => $unit->machine_name !== $cargo?->machine_name && $unit->properties->attack->calculate($player)->totalValue > 1));
        $usable = array_values(array_filter($shooters, static fn (UnitObject $unit): bool => $capital->getObjectAmount($unit->machine_name) > 0
            || ObjectService::getObjectMaxBuildAmount($unit->machine_name, $capital, ObjectService::objectRequirementsMet($unit->machine_name, $capital)) > 0));
        $cheapest = fn (array $units): UnitObject|null => $this->best($units, fn (UnitObject $unit): float => -$this->weighted($unit->price->resources), false);

        return [$probe, $cargo, $cheapest($usable) ?? $cheapest($shooters), $harvester];
    }

    /**
     * The highest-scoring unit; with `$positive`, only one scoring above zero.
     *
     * @param array<UnitObject> $units
     * @param callable(UnitObject): float $score
     */
    private function best(array $units, callable $score, bool $positive): UnitObject|null
    {
        $best = null;
        $top = $positive ? 0.0 : -INF;
        foreach ($units as $unit) {
            if ($score($unit) > $top) {
                [$best, $top] = [$unit, $score($unit)];
            }
        }

        return $best;
    }

    /**
     * Attack and hull of a stack at its owner's research (`players::strength`): the units the host lets fight.
     *
     * @return array{0: float, 1: float}
     */
    private function strength(PlayerService $owner, UnitCollection $units): array
    {
        $attack = 0.0;
        $hull = 0.0;
        foreach ($units->units as $entry) {
            $attack += $entry->amount * $entry->unitObject->properties->attack->calculate($owner)->totalValue;
            $hull += $entry->amount * intdiv($entry->unitObject->properties->structural_integrity->calculate($owner)->totalValue, 10);
        }

        return [$attack, $hull];
    }

    /**
     * The report's owner as the report pictures them: its research section's levels, or ours when it read none
     * (`players::report_research`). A detached player, never saved; nothing the account did not read gets in.
     */
    private function picture(EspionageReport $report, PlayerService $observer): PlayerService
    {
        return $this->pictured[$report->id] ??= app(ReportedPlanet::class)->pictured($report, $observer);
    }

    /** The account's newest espionage report on these coordinates, of any age (`players::last_report`). */
    private function lastReport(int $playerId, Coordinate $to, int $type): EspionageReport|null
    {
        return EspionageReport::query()
            ->whereIn('id', Message::query()->where('user_id', $playerId)->whereNotNull('espionage_report_id')->select('espionage_report_id'))
            ->where('planet_galaxy', $to->galaxy)
            ->where('planet_system', $to->system)
            ->where('planet_position', $to->position)
            ->where('planet_type', $type)
            ->orderByDesc('id')
            ->first();
    }

    /** The value at host prices of every ship and defence a report shows; null when it could not read both sections. */
    private function reportedValue(EspionageReport $report): float|null
    {
        $ships = $report->getAttribute('ships');
        $defence = $report->getAttribute('defense');
        if (!is_array($ships) || !is_array($defence)) {
            return null;
        }

        return $this->value($this->reportUnits([...$ships, ...$defence]));
    }

    /**
     * The fighting units a report section lists.
     *
     * @param array<string, int> $section
     */
    private function reportUnits(array $section): UnitCollection
    {
        $units = new UnitCollection();
        foreach ([...ObjectService::getShipObjects(), ...ObjectService::getDefenseObjects()] as $unit) {
            if ((int) ($section[$unit->machine_name] ?? 0) > 0) {
                $units->addUnit($unit, (int) $section[$unit->machine_name]);
            }
        }

        return $units;
    }

    /** The galaxy view's inactivity mark on the owner of the planet at these coordinates. */
    private function targetInactive(Coordinate $to, int $type): bool
    {
        $owner = Planet::query()->where('galaxy', $to->galaxy)->where('system', $to->system)->where('planet', $to->position)
            ->where('planet_type', $type === PlanetType::DebrisField->value ? PlanetType::Planet->value : $type)->value('user_id');

        return $owner !== null && User::query()->whereKey($owner)->exists() && resolve(PlayerService::class, ['player_id' => (int) $owner])->isInactive();
    }

    /**
     * `players::harvest_plan` at the target: the field the galaxy view shows and the capital's recycle hulls for it,
     * as (ln of its weighted mass, its share of the hold). Zeros when there is no field worth the trip.
     *
     * @return array{0: float, 1: float}
     */
    private function field(PlayerService $player, PlanetService $capital, Coordinate $to, FleetMissionService $fleets): array
    {
        $debris = DebrisField::query()->where('galaxy', $to->galaxy)->where('system', $to->system)->where('planet', $to->position)->first();
        if ($debris === null) {
            return [0.0, 0.0];
        }
        $field = new Resources((float) $debris->metal, (float) $debris->crystal, (float) $debris->deuterium, 0);
        $harvester = ObjectService::getUnitObjectByMachineName(RecycleMission::getHarvesterMachineNameForPosition($to->position));
        $hold = max(1, $harvester->properties->capacity->calculate($player)->totalValue);
        $total = $this->sum($field);
        $count = min((int) ceil($total / $hold), $capital->getObjectAmount($harvester->machine_name));
        if ($count < 1 || $total < intdiv($hold, 2)) {
            return [0.0, 0.0];
        }
        $fuel = (float) $fleets->calculateConsumption($capital, $this->collection([$harvester->machine_name => $count]), $to, 0, self::FULL_SPEED);
        if ($fuel > $capital->deuterium()->get()) {
            return [0.0, 0.0];
        }

        return [$this->ln1p($this->weighted($field)), $this->ratio($this->weighted($field), $hold * $count)];
    }

    /**
     * Hours until the soonest hostile attack lands on the capital (`errands::incoming`), from the account's event list.
     *
     * @param array<string, mixed> $context
     */
    private function incomingHours(array $context, PlanetService $capital): float
    {
        $soonest = null;
        foreach ($context['inbound'] as $fleet) {
            if ((int) $fleet['planet_id_to'] === $capital->getPlanetId() && (int) $fleet['mission_type'] === AttackMission::getTypeId()) {
                $soonest = min($soonest ?? PHP_INT_MAX, (int) $fleet['time_arrival']);
            }
        }

        return $soonest === null ? 0.0 : max(0, $soonest - now()->getTimestamp()) / 3600;
    }

    /** Hours since the last fight on the capital the account's battle messages show; 0 when there was none. */
    private function hoursSinceAttack(PlayerService $player, PlanetService $capital): float
    {
        $coordinates = $capital->getPlanetCoordinates();
        $last = BattleReport::query()->where('planet_user_id', $player->getId())->where('planet_galaxy', $coordinates->galaxy)
            ->where('planet_system', $coordinates->system)->where('planet_position', $coordinates->position)->latest('id')->first();

        return $last?->created_at === null ? 0.0 : max(0, now()->getTimestamp() - $last->created_at->getTimestamp()) / 3600;
    }

    /** The planet's ships that fly and fight as warships (`players::warships`). */
    private function warships(PlayerService $player, UnitCollection $shelf): UnitCollection
    {
        $warships = new UnitCollection();
        foreach ($shelf->units as $entry) {
            if ($entry->amount > 0 && $this->listed($entry->unitObject, ObjectService::getMilitaryShipObjects()) && $entry->unitObject->properties->speed->calculate($player)->totalValue > 0) {
                $warships->addUnit($entry->unitObject, $entry->amount);
            }
        }

        return $warships;
    }

    private function movable(PlayerService $player, UnitCollection $shelf): UnitCollection
    {
        $movable = new UnitCollection();
        foreach ($shelf->units as $entry) {
            if ($entry->amount > 0 && $entry->unitObject->properties->speed->calculate($player)->totalValue > 0) {
                $movable->addUnit($entry->unitObject, $entry->amount);
            }
        }

        return $movable;
    }

    /** @param array<string, int> $amounts */
    private function collection(array $amounts): UnitCollection
    {
        $units = new UnitCollection();
        foreach ($amounts as $name => $amount) {
            if ($amount > 0) {
                $units->addUnit(ObjectService::getUnitObjectByMachineName($name), $amount);
            }
        }

        return $units;
    }

    /** @param array<UnitObject> $among */
    private function listed(UnitObject $unit, array $among): bool
    {
        return in_array($unit->machine_name, array_map(static fn (UnitObject $other): string => $other->machine_name, $among), true);
    }

    /** @param array<string, int|float> $amounts */
    private function resources(array $amounts): Resources
    {
        return new Resources(max(0, (float) ($amounts['metal'] ?? 0)), max(0, (float) ($amounts['crystal'] ?? 0)), max(0, (float) ($amounts['deuterium'] ?? 0)), 0);
    }

    /** Value at host prices, crystal and deuterium weighted as everywhere in plan/rl. */
    private function value(UnitCollection $units): float
    {
        $value = 0.0;
        foreach ($units->units as $entry) {
            $value += $entry->amount * $this->weighted($entry->unitObject->price->resources);
        }

        return $value;
    }

    private function weighted(Resources $resources): float
    {
        return self::WEIGHTS[0] * $resources->metal->get() + self::WEIGHTS[1] * $resources->crystal->get() + self::WEIGHTS[2] * $resources->deuterium->get();
    }

    private function sum(Resources $resources): float
    {
        return $resources->metal->get() + $resources->crystal->get() + $resources->deuterium->get();
    }

    private function ln1p(float|int $value): float
    {
        return log1p(max(0.0, (float) $value));
    }

    private function ratio(float|int $value, float|int $whole): float
    {
        return $whole > 0 ? max(0.0, min(1.0, $value / $whole)) : 0.0;
    }
}
