<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\AI\Domain\Choice\WideErrandEncoder;
use Modules\AI\Domain\Decision\QueueableExpedition;
use Modules\AI\Domain\Decision\QueueableJumpGate;
use Modules\AI\Domain\Decision\QueueableMissile;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\QueueableRecycle;
use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\EspionageMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BattleReport;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl/STRONG-PLAYER-PLAN.md "Situation columns: no same-situation cross-check". The simulator's arm `errand-dump`
// (examples/season.rs) writes one model login's errand point with the situation it was built from; the account is
// rebuilt here through `ai:rl-import-sim`, what the account saw (its reports, the galaxy view's owners, inactivity and
// debris, its last fight, the attacks on its event list) is put where the host keeps it, and every simulator row is
// encoded by WideErrandEncoder from the same decision (target, fleet, mission). The columns that still differ are named
// with their cause in SITUATION_KNOWN; any other difference fails.

const SITUATION_BASE = '2026-10-05T00:00:00Z';
const SITUATION_TOLERANCE = 1e-3;

/** @return list<string> the simulator dumps the test replays */
function situationDumps(): array
{
    return glob(__DIR__ . '/../Fixtures/errand-v3-situation-*.json') ?: [];
}

/** The simulator's kind (Rust `Debug`) as the encoder's kind name. */
function situationKind(string $kind): string
{
    return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $kind));
}

/** @param array<string, int|float> $byId host id => amount */
function situationUnits(array $byId): UnitCollection
{
    $units = new UnitCollection();
    foreach ($byId as $id => $amount) {
        if ($amount > 0) {
            $units->addUnit(ObjectService::getUnitObjectById((int) $id), (int) $amount);
        }
    }

    return $units;
}

/**
 * @param array<string, int|float>|null $byId
 * @return array<string, int>|null
 */
function situationNames(array|null $byId): array|null
{
    if ($byId === null) {
        return null;
    }
    $named = [];
    foreach ($byId as $id => $amount) {
        $named[ObjectService::getObjectById((int) $id)->machine_name] = (int) $amount;
    }

    return $named;
}

/** @param array<string, mixed> $coord */
function situationCoordinate(array $coord): Coordinate
{
    return new Coordinate((int) $coord['galaxy'], (int) $coord['system'], (int) $coord['position']);
}

/** @param array<string, mixed> $coord */
function situationType(array $coord): int
{
    return ($coord['moon'] ?? false) ? PlanetType::Moon->value : PlanetType::Planet->value;
}

/**
 * A target as the galaxy view shows it: a planet (and its moon) at the coordinates, its owner's inactivity mark, the
 * debris field over it. Reuses whatever already sits there (the account's own body, or a seeded home).
 *
 * @param array<string, mixed> $target
 */
function situationTarget(array $target, int $selfId): Planet
{
    $at = situationCoordinate($target['coord']);
    $find = static fn (int $type): Planet|null => Planet::query()->where('galaxy', $at->galaxy)->where('system', $at->system)->where('planet', $at->position)->where('planet_type', $type)->first();
    $planet = $find(PlanetType::Planet->value);
    if ($planet === null) {
        $owner = User::factory()->create(['time' => (string) (now()->getTimestamp() - ($target['inactive'] ? 8 * 86_400 : 600))]);
        $planet = Planet::factory()->create(['user_id' => $owner->id, 'galaxy' => $at->galaxy, 'system' => $at->system, 'planet' => $at->position, 'planet_type' => PlanetType::Planet->value]);
    }
    if ($planet->user_id !== $selfId) {
        User::query()->whereKey($planet->user_id)->update(['time' => (string) (now()->getTimestamp() - ($target['inactive'] ? 8 * 86_400 : 600))]);
    }
    if (($target['coord']['moon'] ?? false) && $find(PlanetType::Moon->value) === null) {
        Planet::factory()->create(['user_id' => $planet->user_id, 'galaxy' => $at->galaxy, 'system' => $at->system, 'planet' => $at->position, 'planet_type' => PlanetType::Moon->value,
            'diameter' => (int) ($target['moon_diameter'] ?? 8000)]);
    }
    if ($target['field'] && !DebrisField::query()->where('galaxy', $at->galaxy)->where('system', $at->system)->where('planet', $at->position)->exists()) {
        [$metal, $crystal, $deuterium] = $target['debris'];
        DebrisField::query()->create(['galaxy' => $at->galaxy, 'system' => $at->system, 'planet' => $at->position, 'metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deuterium]);
    }

    return $planet;
}

/**
 * The account's newest report on a target, as the host files it, dated by its age at the login.
 *
 * @param array<string, mixed> $report
 */
function situationReport(PlayerService $player, Coordinate $at, int $type, int $ownerId, array $report): void
{
    [$metal, $crystal, $deuterium] = $report['resources'];
    $espionage = new EspionageReport();
    $espionage->planet_galaxy = $at->galaxy;
    $espionage->planet_system = $at->system;
    $espionage->planet_position = $at->position;
    $espionage->planet_type = $type;
    $espionage->planet_user_id = $ownerId;
    $espionage->resources = ['metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deuterium, 'energy' => 0];
    $espionage->debris = $report['debris'] === null ? null : array_combine(['metal', 'crystal', 'deuterium'], $report['debris']);
    $espionage->ships = situationNames($report['ships']);
    $espionage->defense = situationNames($report['defence']);
    $espionage->buildings = situationNames($report['buildings']);
    $espionage->research = situationNames($report['research']);
    $espionage->player_info = ['player_name' => 'Target', 'player_status' => ''];
    $espionage->save();
    $espionage->forceFill(['created_at' => now()->subSeconds((int) round($report['age_seconds']))])->save();
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $espionage->id);
}

/** The account's own body at these coordinates. */
function situationBody(PlayerService $player, Coordinate $at, bool $moon): PlanetService
{
    $body = $moon ? $player->planets->getMoonByCoordinates($at) : $player->planets->getPlanetByCoordinates($at);
    expect($body)->not->toBeNull();

    return $body;
}

function situationCall(WideErrandEncoder $encoder, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($encoder, $method))->invoke($encoder, ...$arguments);
}

/**
 * The encoder's row for one simulator row: the decision (target, fleet, mission, speed) is the simulator's, handed to the
 * encoder's own trip builder for that kind where it takes a decision, and as the trip the encoder reads otherwise.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $context
 * @return list<float>
 */
function situationRow(WideErrandEncoder $encoder, array $row, array $context): array
{
    /** @var PlayerService $player */
    $player = $context['player'];
    /** @var PlanetService $capital */
    $capital = $context['capital'];
    $kind = situationKind($row['kind']);
    $fleet = $row['launched'][0] ?? null;
    $target = $row['target'];
    $to = $target === null ? null : situationCoordinate($target['coord']);
    $type = $target === null ? PlanetType::Planet->value : situationType($target['coord']);
    $origin = $fleet === null ? $capital : situationBody($player, situationCoordinate($fleet['from_coord']), (bool) $fleet['from_coord']['moon']);
    $speed = $row['plan'] === 'WarSlow' ? 5.0 : 10.0;
    $flown = static fn (string $as): array => ['kind' => $as, 'origin' => $origin, 'to' => situationCoordinate($fleet['to']), 'type' => $fleet['to_moon'] || ($target['coord']['moon'] ?? false) ? PlanetType::Moon->value : PlanetType::Planet->value,
        'units' => situationUnits($fleet['ships']), 'mission' => (int) $fleet['mission'], 'speed' => $speed];
    $raid = static fn (): array|null => situationCall($encoder, 'raidTrip', app()->makeWith(QueueableRaid::class, [
        'originPlanetId' => $origin->getPlanetId(), 'targetGalaxy' => $to->galaxy, 'targetSystem' => $to->system, 'targetPosition' => $to->position,
        'targetType' => $fleet['to_moon'] ? PlanetType::Moon->value : $type, 'missionType' => (int) $fleet['mission'], 'launchUnits' => situationNames($fleet['ships']),
    ]), $player);

    $flies = !in_array($kind, ['wait', 'order', 'spy', 'strike', 'gate_save', 'recall'], true);
    $trip = match (true) {
        $flies && $fleet === null => ['kind' => $kind, 'origin' => $capital, 'to' => $to ?? $capital->getPlanetCoordinates()],
        default => situationTrip($encoder, $kind, $row, $context, $origin, $to, $type, $speed, $flown, $raid),
    };

    return situationCall($encoder, 'row', $kind, $trip, $context);
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $context
 * @return array<string, mixed>|null
 */
function situationTrip(WideErrandEncoder $encoder, string $kind, array $row, array $context, PlanetService $origin, Coordinate|null $to, int $type, float $speed, Closure $flown, Closure $raid): array|null
{
    $player = $context['player'];
    $capital = $context['capital'];
    $fleet = $row['launched'][0] ?? null;
    $target = $row['target'];

    return match ($kind) {
        'wait' => null,
        'order' => ['kind' => 'order', 'unit' => ObjectService::getUnitObjectById((int) $row['unit'])],
        'spy' => situationCall($encoder, 'spyTrip', app()->makeWith(QueueableSpy::class, ['planetId' => $capital->getPlanetId(), 'targetGalaxy' => $to->galaxy, 'targetSystem' => $to->system, 'targetPosition' => $to->position, 'targetType' => $type, 'missionType' => EspionageMission::getTypeId()]), $player),
        'raid', 'moon_destroy' => $raid() + ['speed' => $speed],
        'phalanx' => ['kind' => 'phalanx'] + $raid(),
        'recycle' => situationCall($encoder, 'recycleTrip', app()->makeWith(QueueableRecycle::class, ['planetId' => $origin->getPlanetId(), 'targetGalaxy' => $to->galaxy, 'targetSystem' => $to->system, 'targetPosition' => $to->position, 'targetType' => $type,
            'missionType' => (int) $fleet['mission'], 'mass' => (float) array_sum($target['debris'])]), $player),
        'strike' => situationCall($encoder, 'strikeTrip', app()->makeWith(QueueableMissile::class, ['originPlanetId' => $capital->getPlanetId(), 'targetGalaxy' => $to->galaxy, 'targetSystem' => $to->system, 'targetPosition' => $to->position, 'targetType' => $type, 'missiles' => 0]), $player),
        'expedition' => situationCall($encoder, 'expeditionTrip', app()->makeWith(QueueableExpedition::class, ['planetId' => $origin->getPlanetId(), 'galaxy' => $fleet['to']['galaxy'], 'system' => $fleet['to']['system'], 'position' => $fleet['to']['position']]), $player),
        'gate_save' => situationCall($encoder, 'gateTrip', app()->makeWith(QueueableJumpGate::class, ['sourceMoonId' => situationBody($player, $to, true)->getPlanetId(), 'targetMoonId' => 0]), $context),
        'recall' => ['kind' => 'recall', 'origin' => $capital, 'to' => $capital->getPlanetCoordinates()],
        default => $flown($kind),
    };
}

/**
 * Rebuilds the dump's situation and encodes it: [state, rows] beside the simulator's.
 *
 * @param array<string, mixed> $dump
 * @return array{state: list<float>, rows: list<list<float>>}
 */
function situationEncode(array $dump): array
{
    $levels = tempnam(sys_get_temp_dir(), 'situation-levels-');
    $universe = tempnam(sys_get_temp_dir(), 'situation-') . '.sqlite';
    file_put_contents($levels, json_encode([$dump['account']]));
    $at = CarbonImmutable::parse(SITUATION_BASE)->addSeconds((int) round($dump['t']));
    Artisan::call('ai:rl-import-sim', ['path' => $universe, 'levels' => $levels, '--accounts' => 1, '--at' => $at->toIso8601String()]);

    $playerId = (int) AiProfile::query()->orderBy('player_id')->value('player_id');
    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    $capital = $player->planets->first();
    foreach ($dump['rows'] as $row) {
        $target = $row['target'];
        if ($target === null) {
            continue;
        }
        $planet = situationTarget($target, $playerId);
        $coordinate = situationCoordinate($target['coord']);
        if ($target['report'] !== null) {
            situationReport($player, $coordinate, situationType($target['coord']), (int) $planet->user_id, $target['report']);
        }
        if ($target['moon_report'] !== null) {
            situationReport($player, $coordinate, PlanetType::Moon->value, (int) $planet->user_id, $target['moon_report']);
        }
    }
    $home = $capital->getPlanetCoordinates();
    if (($dump['home_field'] ?? false) && !DebrisField::query()->where('galaxy', $home->galaxy)->where('system', $home->system)->where('planet', $home->position)->exists()) {
        [$metal, $crystal, $deuterium] = $dump['home_debris'];
        DebrisField::query()->create(['galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $home->position, 'metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deuterium]);
    }
    if ($dump['last_attacked_seconds'] !== null) {
        $fight = new BattleReport();
        $coordinates = $capital->getPlanetCoordinates();
        $fight->forceFill(['planet_galaxy' => $coordinates->galaxy, 'planet_system' => $coordinates->system, 'planet_position' => $coordinates->position, 'planet_user_id' => $playerId])->save();
        $fight->forceFill(['created_at' => now()->subSeconds((int) round($dump['last_attacked_seconds']))])->save();
    }

    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    $capital = $player->planets->first();
    $inbound = array_map(static fn (float|int $seconds): array => ['planet_id_to' => $capital->getPlanetId(), 'mission_type' => AttackMission::getTypeId(),
        'time_arrival' => now()->getTimestamp() + (int) round($seconds)], $dump['incoming_seconds']);
    $context = ['player' => $player, 'capital' => $capital, 'fleets' => app()->makeWith(FleetMissionService::class, ['player' => $player]), 'inbound' => $inbound, 'absence' => null];
    $encoder = app(WideErrandEncoder::class);

    return [
        'state' => $encoder->state($context),
        'rows' => array_map(static fn (array $row): array => situationRow($encoder, $row, $context), $dump['rows']),
        'path' => $universe,
        'notes' => ['fleet_slots' => [$player->getFleetSlotsMax(), $dump['fleet_slots'], $dump['fleets_out']], 'expedition_slots' => [$player->getExpeditionSlotsMax(), $dump['expedition_slots'], $dump['expeditions_out']],
            'capacity' => [$capital->metalStorage()->get(), $capital->crystalStorage()->get(), $capital->deuteriumStorage()->get(), $dump['account']['planets'][0]['cap']],
            'resources' => [$capital->getResources()->metal->get(), $capital->getResources()->crystal->get(), $capital->getResources()->deuterium->get(), $dump['account']['planets'][0]['res']],
            'coordinates' => [(string) $capital->getPlanetCoordinates()->asString(), $dump['account']['planets'][0]['galaxy'], $dump['account']['planets'][0]['system'], $dump['account']['planets'][0]['position']]],
    ];
}

/**
 * Per column: the largest |PHP - simulator| and the rows (kind/plan) where it exceeds the tolerance.
 *
 * @param array<string, mixed> $dump
 * @param array<string, mixed> $encoded
 * @return array<string, array{gap: float, rows: list<string>}>
 */
function situationGaps(array $dump, array $encoded): array
{
    $gaps = [];
    foreach ($dump['state_names'] as $i => $name) {
        $gap = abs($encoded['state'][$i] - $dump['state'][$i]);
        $gaps['state.' . $name] = ['gap' => $gap, 'rows' => $gap > SITUATION_TOLERANCE ? [sprintf('php %.6g sim %.6g', $encoded['state'][$i], $dump['state'][$i])] : []];
    }
    foreach ($dump['candidate_names'] as $i => $name) {
        $gaps['cand.' . $name] = ['gap' => 0.0, 'rows' => []];
        foreach ($dump['rows'] as $r => $row) {
            $gap = abs($encoded['rows'][$r][$i] - $row['features'][$i]);
            $gaps['cand.' . $name]['gap'] = max($gaps['cand.' . $name]['gap'], $gap);
            if ($gap > SITUATION_TOLERANCE) {
                $gaps['cand.' . $name]['rows'][] = sprintf('%d %s/%s php %.6g sim %.6g', $r, $row['kind'], $row['plan'], $encoded['rows'][$r][$i], $row['features'][$i]);
            }
        }
    }

    return $gaps;
}

/**
 * Columns that differ for a cause outside the encoder, by the rows (`Kind/Plan`, `Kind/*`, or `*` for the state) they may
 * differ on, each with its cause.
 *
 * @var array<string, array{0: list<string>, 1: string}>
 */
const SITUATION_KNOWN = [
    'state.idle' => [['*'], 'the import carries no build queue; the simulator\'s idle is a login with no build decision'],
    'state.expedition_free' => [['*'], 'fleets in flight are not carried (the levels dump counts them at their origin)'],
    'state.fleet_slots_free' => [['*'], 'fleets in flight are not carried'],
    'cand.expedition_free' => [['Expedition/*'], 'fleets in flight are not carried'],
    'cand.order_share' => [['Order/Hull'], 'the host offers only the scripted want of a unit, never a hull order of what the stock pays for'],
    'cand.order_over_stock' => [['Order/Hull'], 'as order_share'],
    'cand.flight_hours' => [['Expedition/*', 'Save/Night'], 'the host sends its own expedition fleet in one row (the simulator prices three variants); no night save in the host'],
    'cand.fleet_value' => [['Expedition/*', 'Save/Night'], 'the host sends its own expedition fleet; no night save in the host (the simulator prices all its waves)'],
    'cand.doubler' => [['Expedition/*'], 'one host expedition row, the riders it can send; the simulator has a row per variant'],
    'cand.escort' => [['Expedition/*'], 'as doubler'],
    'cand.distance' => [['Save/Night'], 'no night save in the host; the simulator prices it at the account\'s own slot'],
    'cand.fuel_over_stock' => [['Save/Night'], 'as distance'],
    'cand.field' => [['Save/Night'], 'as distance'],
    'cand.field_over_capacity' => [['Save/Night'], 'as distance'],
];

beforeEach(function (): void {
    $this->situationConnection = (string) config('database.default');
});

afterEach(function (): void {
    // `ai:rl-import-sim` points the application at its SQLite universe; the next test runs on the suite's database.
    config(['database.default' => $this->situationConnection]);
    DB::setDefaultConnection($this->situationConnection);
    foreach ([SettingsService::class, PlayerServiceFactory::class, PlanetServiceFactory::class] as $cached) {
        app()->forgetInstance($cached);
    }
});

test('the simulator\'s errand point is encoded column for column from the same situation', function (string $path): void {
    $dump = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect($dump['state_names'])->toBe(WideErrandEncoder::STATE)
        ->and($dump['candidate_names'])->toBe(WideErrandEncoder::candidateNames());

    $encoded = situationEncode($dump);
    $gaps = situationGaps($dump, $encoded);
    @unlink($encoded['path']);
    $out = getenv('AI_SITUATION_PARITY_OUT');
    if (is_string($out) && $out !== '') {
        file_put_contents($out . '/' . basename($path, '.json') . '.gaps.json', json_encode(['notes' => $encoded['notes'], 'gaps' => $gaps], JSON_PRETTY_PRINT));
    }

    $unexplained = [];
    foreach ($gaps as $column => $gap) {
        $known = SITUATION_KNOWN[$column] ?? null;
        $rows = array_values(array_filter($gap['rows'], static function (string $row) use ($known): bool {
            $label = explode(' ', $row)[1];

            return $known === null || ($known[0] !== ['*'] && array_intersect([$label, explode('/', $label)[0] . '/*'], $known[0]) === []);
        }));
        if ($rows !== []) {
            $unexplained[$column] = $rows;
        }
    }

    expect($unexplained)->toBe([]);
})->with(situationDumps());
