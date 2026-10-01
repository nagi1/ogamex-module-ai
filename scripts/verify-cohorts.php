<?php

/**
 * What the cohorts are actually doing, said cheaply.
 *
 * DEV TOOLING — read-only, never shipped behaviour, never run in a universe. Run it in the GRAND or
 * PVE app container, never the dev stack: those two are the real cohorts.
 *
 *   docker compose -f local-docker-dev/docker-compose.grand.yml exec -T ogamex-app \
 *     sh -lc "cd /var/www && php artisan tinker --execute=\"require '/var/www/Modules/AI/scripts/verify-cohorts.php';\""
 *
 * The default pass is SQL counters plus one aggregate per defence object — about twenty queries, so
 * it answers instantly on a 20-account cohort. The per-account detail is expensive on purpose (it
 * builds the real perception, which runs every planner) and is therefore opt-in: pass ids, e.g.
 *   ... --execute="\$argv = [12]; require '.../verify-cohorts.php';"
 */

use Illuminate\Support\Facades\Schema;
use Modules\AI\Domain\Decision\DefenseCompositionPlanner;
use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Domain\Decision\QueueableBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Domain\Decision\ReserveFloor;
use Modules\AI\Domain\Lifecycle\AccountStateResolver;
use Modules\AI\Domain\Perception\PlayerObservationService;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\BuildingQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

echo "\n=== cohort verification — ".now()->toDateTimeString()." ===\n";

$profiles = AiProfile::query()->where('enabled', true)->get();

if ($profiles->isEmpty()) {
    echo "No enabled AI accounts in this universe.\n";

    return;
}

$playerIds = $profiles->pluck('player_id')->map(fn ($id): int => (int) $id)->all();
$planetIds = DB::table('planets')->whereIn('user_id', $playerIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();

// One aggregate per defence object for the whole cohort, rather than one per object per planet:
// 10 queries instead of ~1,800 on a mature universe, and the number is the same.
$defenceUnits = 0;
$defenceDetail = [];
foreach (ObjectService::getDefenseObjects() as $object) {
    $amount = (int) DB::table('planets')->whereIn('id', $planetIds)->sum($object->machine_name);
    $defenceUnits += $amount;
    if ($amount > 0) {
        $defenceDetail[$object->machine_name] = $amount;
    }
}

$orders = [];
$inflight = [];
$missing = [];
foreach (['building_queues', 'research_queues', 'unit_queues'] as $table) {
    if (! Schema::hasTable($table)) {
        // Loud, not zero: silently reading a wrong table name is how a busy cohort looks dead.
        $missing[] = $table;
        $orders[$table] = 0;
        $inflight[$table] = 0;

        continue;
    }
    // Rows in flight are a bad activity measure: these universes run fast enough to drain a queue
    // in seconds, so a healthy cohort reads as zero. Orders *placed* in the last ten minutes is the
    // number that says whether it is playing.
    $orders[$table] = DB::table($table)
        ->whereIn('planet_id', $planetIds)
        ->where('created_at', '>=', now()->subMinutes(10))
        ->count();
    $inflight[$table] = DB::table($table)
        ->whereIn('planet_id', $planetIds)
        ->where('processed', 0)
        ->count();
}

if ($missing !== []) {
    echo 'MISSING TABLES: '.implode(', ', $missing)." — the counts below are meaningless\n";
}

$states = DB::table('ai_work_items')
    ->whereIn('player_id', $playerIds)
    ->selectRaw('state, count(*) as total')
    ->groupBy('state')
    ->pluck('total', 'state')
    ->all();

printf(
    "profiles: %d   planets: %d   defence units: %s\n"
    ."orders last 10 min: building %d, research %d, units %d   (in flight: %d/%d/%d)\n"
    ."receipts: %s   decisions: %s   work items by state: %s\n",
    $profiles->count(),
    count($planetIds),
    number_format($defenceUnits),
    $orders['building_queues'],
    $orders['research_queues'],
    $orders['unit_queues'],
    $inflight['building_queues'],
    $inflight['research_queues'],
    $inflight['unit_queues'],
    number_format(DB::table('ai_action_receipts')->whereIn('player_id', $playerIds)->count()),
    number_format(DB::table('ai_decision_traces')->whereIn('player_id', $playerIds)->count()),
    json_encode($states)
);

if ($defenceDetail !== []) {
    echo 'defence held: '.json_encode($defenceDetail)."\n";
}

// Research is the other half of a real account: a cohort that never researches cannot colonise,
// cannot expedition and cannot fleet-crash, which is the difference between a player and a miner.
// Astrophysics is the usual gate — every later account feature hangs off it.
if (Schema::hasTable('users_tech')) {
    $astrophysics = (int) DB::table('users_tech')->whereIn('user_id', $playerIds)->sum('astrophysics');
    $atZero = DB::table('users_tech')->whereIn('user_id', $playerIds)->where('astrophysics', 0)->count();

    printf(
        "astrophysics: %d level(s) across the cohort, %d of %d accounts still at 0\n",
        $astrophysics,
        $atZero,
        count($playerIds)
    );
}

// Research health. A cancelled row is a decision the host refused, and nothing ever marks a
// cancelled row processed, so a lifetime total cannot say whether it is happening now: read the
// newest rows instead.
if (Schema::hasTable('research_queues')) {
    $recent = DB::table('research_queues')->orderByDesc('id')->limit(300)->get();

    printf(
        "research (newest 300 rows): %d cancelled, %d completed, %d waiting\n",
        $recent->where('canceled', 1)->count(),
        $recent->where('processed', 1)->count(),
        $recent->where('canceled', 0)->where('processed', 0)->count()
    );

    // A rolling window, because the newest-300 view mixes whatever a fix changed with what came
    // before it. This is the number that says whether the cohort still cancels its own research.
    $windowStart = now()->subMinutes(20);
    $window = DB::table('research_queues')->where('created_at', '>=', $windowStart);

    printf(
        "research (last 20 min): %d rows, %d cancelled\n",
        (clone $window)->count(),
        (clone $window)->where('canceled', 1)->count()
    );

    $sample = DB::table('research_queues')->orderByDesc('id')->where('canceled', 1)->first();
    if ($sample !== null) {
        printf(
            "  newest cancelled: planet %d, object %d, target level %d, cost %d, created %s\n",
            $sample->planet_id,
            $sample->object_id,
            $sample->object_level_target,
            $sample->metal,
            $sample->created_at
        );
    }
}

printf(
    "due now: %s   leased: %s   pending: %s\n",
    // Only work that is still waiting: without the state filter this counts the million rows that
    // are already completed and past their due time, so it read "1,089,229 due" about a cohort with
    // fifty items outstanding and made a healthy queue look like a backlog (found 30 Sep 2026).
    number_format(DB::table('ai_work_items')->whereIn('player_id', $playerIds)
        ->whereIn('state', [AiWorkState::Pending->value, AiWorkState::Retry->value])
        ->where('due_at', '<=', now())->count()),
    number_format($states[2] ?? 0),
    number_format($states[1] ?? 0)
);

// Quality, not liveness. Everything above proves the accounts *play*; none of it can tell whether
// they play *well*, which is how a cohort spent a whole session walling one planet and leaving 125
// naked while every counter above read healthy (found 29 Sep 2026). These are the three shapes the
// damage took, stated as thresholds so a human reading the log sees the defect named. They are
// measurement thresholds for this log, not module policy: nothing in the module reads them.
//
//   NAKED_BESIDE_WALLED  an account with a planet at zero defence while a sibling holds a real wall
//   WALL_CEILING         one planet holding more defence units than any single planet needs
//   ALLIANCE_SHARE       one alliance holding more than this share of the AI accounts
//   IDLE_QUEUES          an account that played in the last hour with most buildable planets' queues empty
//   UNIVERSE_SPEED       the economy or a fleet speed above the 1000x grand-test protocol
$nakedBesideWalled = [];
$overCeiling = [];
$WALL_CEILING = 20_000;
$ALLIANCE_SHARE = 0.6;

$defenceSum = collect(ObjectService::getDefenseObjects())
    ->map(fn ($object): string => '`'.$object->machine_name.'`')
    ->implode(' + ');

// Moons are left out when the host says which rows are moons: a moon without defence is ordinary,
// and counting it would make this invariant cry wolf until nobody reads it.
$planetQuery = DB::table('planets')->whereIn('user_id', $playerIds);
if (Schema::hasColumn('planets', 'planet_type')) {
    $planetQuery->where('planet_type', '!=', 3);
}

$byAccount = [];
foreach ($planetQuery->selectRaw("user_id, id, ($defenceSum) as defence")->get() as $row) {
    $byAccount[(int) $row->user_id][] = ['id' => (int) $row->id, 'defence' => (int) $row->defence];
}

foreach ($byAccount as $accountId => $planets) {
    $walls = array_column($planets, 'defence');
    $strongest = max($walls);
    $naked = count(array_filter($walls, fn (int $units): bool => $units === 0));

    if ($naked > 0 && $strongest > 1000) {
        $nakedBesideWalled[] = sprintf('player %d: %d planet(s) at zero defence while one holds %s units', $accountId, $naked, number_format($strongest));
    }

    foreach ($planets as $planet) {
        if ($planet['defence'] <= $WALL_CEILING) {
            continue;
        }
        $overCeiling[] = sprintf('planet %d holds %s defence units', $planet['id'], number_format($planet['defence']));
    }
}

$allianceShare = [];
if (Schema::hasColumn('users', 'alliance_id')) {
    $members = DB::table('users')->whereIn('id', $playerIds)->whereNotNull('alliance_id')
        ->selectRaw('alliance_id, count(*) as members')->groupBy('alliance_id')->pluck('members', 'alliance_id');

    foreach ($members as $allianceId => $count) {
        $share = $count / count($playerIds);
        if ($share > $ALLIANCE_SHARE) {
            $allianceShare[] = sprintf('alliance %s holds %d of %d AI accounts (%.0f%%)', $allianceId, $count, count($playerIds), $share * 100);
        }
    }
}

// A player who logs in fills every planet's build queue where something can be built. IDLE_QUEUES
// counts only the planets the planner has a step for and that have no building in progress: a planet
// whose every candidate the host refuses (no free field, lab busy, price plus reserve) is SATURATED,
// which is information about the universe, not a defect of the account, and is printed below.
$IDLE_SHARE = 0.5;
$idleQueues = [];
$saturated = [];
$playedThisHour = DB::table('ai_work_items')->whereIn('player_id', $playerIds)
    ->where('kind', AiWorkKind::RunSession->value)->where('updated_at', '>=', now()->subHour())
    ->distinct()->pluck('player_id')->map(fn ($id): int => (int) $id)->all();
$busyPlanets = BuildingQueue::query()->where('processed', 0)->where('time_end', '>', time())
    ->distinct()->pluck('planet_id')->map(fn ($id): int => (int) $id)->all();

// A planet whose build order is already queued is being filled: the session that placed it is done, the
// order runs a few minutes later, and at 1000x the planet reads idle in between.
$ordered = [];
foreach (DB::table('ai_work_items')->whereIn('player_id', $playerIds)->where('kind', AiWorkKind::BuildFirstBuilding->value)
    ->whereIn('state', [AiWorkState::Pending->value, AiWorkState::Retry->value, AiWorkState::Leased->value])->pluck('payload') as $payload) {
    $ordered[] = (int) (json_decode((string) $payload, true)['planet_id'] ?? 0);
}

$buildingPlanner = app(QueueableBuildingPlanner::class);

foreach ($playedThisHour as $accountId) {
    $ownPlanets = array_column($byAccount[$accountId] ?? [], 'id');
    if ($ownPlanets === []) {
        continue;
    }

    $player = app(PlayerServiceFactory::class)->make($accountId, true);
    $planned = array_map(static fn ($step): int => $step->planetId, $buildingPlanner->steps($accountId, $player));
    // At 1000x a build ends in minutes, so a planet read between its last build ending and the
    // account's next turn is waiting, not neglected. Idle means the account finished a session
    // after the planet's last build ended and the planet still has a legal step and no build.
    $lastSession = (int) strtotime((string) DB::table('ai_work_items')->where('player_id', $accountId)
        ->where('kind', AiWorkKind::RunSession->value)->where('state', AiWorkState::Completed->value)->max('updated_at'));
    $lastBuildEnd = BuildingQueue::query()->whereIn('planet_id', $ownPlanets)->where('time_end', '<=', time())
        ->groupBy('planet_id')->selectRaw('planet_id, max(time_end) as ended')->pluck('ended', 'planet_id');
    $idle = array_filter($ownPlanets, static fn (int $id): bool => in_array($id, $planned, true)
        && !in_array($id, $busyPlanets, true)
        && !in_array($id, $ordered, true)
        && $lastSession > (int) ($lastBuildEnd[$id] ?? 0));

    if (count($idle) / count($ownPlanets) > $IDLE_SHARE) {
        $idleQueues[] = sprintf('player %d played this hour with %d of %d planets buildable and idle', $accountId, count($idle), count($ownPlanets));
    }

    $reasons = [];
    $profile = AiProfile::query()->where('player_id', $accountId)->first();
    foreach ($player->planets->all() as $planet) {
        $id = $planet->getPlanetId();
        if (!in_array($id, $ownPlanets, true) || in_array($id, $planned, true) || in_array($id, $busyPlanets, true)) {
            continue;
        }
        $reason = saturation_reason($buildingPlanner, $profile, $planet);
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
    }
    if ($reasons !== []) {
        $saturated[] = sprintf('player %d: %d planet(s) with every candidate refused (%s)', $accountId, array_sum($reasons), implode(', ', array_map(
            static fn (string $reason, int $count): string => "{$reason}: {$count}",
            array_keys($reasons),
            $reasons,
        )));
    }
}

/**
 * Why a planet that nothing could be queued on is not building: the refusal of its first candidate,
 * asked of the planner's own gates. A technology among the candidates means the lab is taken.
 */
function saturation_reason(QueueableBuildingPlanner $planner, AiProfile $profile, PlanetService $planet): string
{
    foreach ($planner->passes($profile) as $candidates) {
        foreach ($candidates($planet) as $candidate) {
            if (ObjectService::getObjectById($candidate->buildingId)->type === GameObjectType::Research) {
                return 'lab busy';
            }

            return $planner->refusal($planet, $candidate) ?? 'queueable';
        }
    }

    return 'no candidate';
}

// The grand-test protocol runs the economy at 1000x (local-docker-dev/capacity-run.sh). A cohort run
// faster than that fills every field and banks billions in a day (90,000x did, on 1 Oct 2026), and
// every human-timescale measure stops meaning anything; reseed instead of raising it.
$UNIVERSE_SPEED = 1000;
$tooFast = [];
if (Schema::hasTable('settings')) {
    $speeds = DB::table('settings')->where('key', 'economy_speed')->orWhere('key', 'like', 'fleet_speed%')->pluck('value', 'key');
    foreach ($speeds as $name => $value) {
        if ((float) $value > $UNIVERSE_SPEED) {
            $tooFast[] = sprintf('%s is %s, above the %dx grand-test protocol', $name, $value, $UNIVERSE_SPEED);
        }
    }
}

// Named invariants, one line per violation, and a machine-readable verdict at the end. The harness
// reads that line to raise a task for anything the cohorts fail, so a repeated "QUALITY: FAIL" is not
// a dead end someone has to notice by eye.
$invariants = [
    'NAKED_BESIDE_WALLED' => $nakedBesideWalled,
    'WALL_CEILING' => $overCeiling,
    'ALLIANCE_SHARE' => $allianceShare,
    'IDLE_QUEUES' => $idleQueues,
    'UNIVERSE_SPEED' => $tooFast,
];

$fired = array_keys(array_filter($invariants, static fn (array $rows): bool => $rows !== []));
$total = array_sum(array_map('count', $invariants));

echo "\nQUALITY: ".($total === 0
    ? count($invariants).' of '.count($invariants).' invariants honoured'
    : $total.' violation(s) across '.count($fired).' invariant(s)')."\n";

foreach ($invariants as $name => $rows) {
    foreach ($rows as $row) {
        echo '  ! ['.$name.'] '.$row."\n";
    }
}

echo $fired === [] ? '' : 'QUALITY: FAIL '.implode(' ', $fired)."\n";

// Information, not a violation: planets the planner had nothing to queue on because the host refused
// every candidate. When this covers most planets of most accounts the run is over (reseed).
foreach ($saturated as $row) {
    echo '  SATURATED '.$row."\n";
}

// The expensive part, only for the accounts asked for. This is what answers "why is this account
// doing nothing": the account's state, the capabilities the perception publishes, the step the
// chain wants next, and the wall the doctrine would build.
require_once __DIR__.'/economy-explain.php';

foreach (($argv ?? []) as $playerId) {
    $playerId = (int) $playerId;
    foreach (explain_economy($playerId) as $line) {
        echo '  economy '.$line."\n";
    }
    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    $observation = app(PlayerObservationService::class)->ownedState($playerId);
    $published = array_keys(array_filter($observation['available_actions']));

    printf(
        "\nplayer %d  state %s  published %s  planets %d\n",
        $playerId,
        app(AccountStateResolver::class)->resolve($playerId)->name,
        $published === [] ? 'NOTHING' : implode(', ', $published),
        count($player->planets->all())
    );

    foreach (array_slice($player->planets->all(), 0, 3) as $planet) {
        $planet->updateResources(false);
        $planet->updateResourceProductionStats(false);
        $planet->updateResourceStorageStats(false);

        $resources = $planet->getResources();
        $floor = app(ReserveFloor::class)->floor($planet, ReserveFloor::ECONOMY_HOURS);
        $step = app(QueueableBuildingPlanner::class)->plan($playerId, $player);
        $need = app(DefenseNeedEvaluator::class)->evaluate($player, $planet);
        $wall = app(DefenseCompositionPlanner::class)->plan($player, $planet, $need);

        $next = match (true) {
            $step instanceof QueueableBuilding => ObjectService::getObjectById($step->buildingId)->machine_name.' ('.$step->reason.')',
            $step instanceof QueueableResearch => ObjectService::getObjectById($step->researchId)->machine_name.' ('.$step->reason.')',
            default => 'NONE — only DoNothing offered',
        };

        $built = 0;
        foreach (ObjectService::getDefenseObjects() as $object) {
            $built += $planet->getObjectAmount($object->machine_name);
        }

        $queued = [];
        foreach (['building_queues', 'research_queues', 'unit_queues'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->where('planet_id', $planet->getPlanetId())->where('processed', 0)->count();
            if ($rows > 0) {
                $queued[] = $table.' x'.$rows;
            }
        }

        printf(
            "  p%d %s  metal %s (+%s/h) crystal %s deuterium %s  reserve %s\n"
            ."     next: %s\n     defence %d  need %s  wall %s  queued: %s\n",
            $planet->getPlanetId(),
            $planet->getPlanetName(),
            number_format($resources->metal->get()),
            number_format($planet->getMetalProductionPerHour()),
            number_format($resources->crystal->get()),
            number_format($resources->deuterium->get()),
            number_format($floor->metal->get()),
            $next,
            $built,
            $need === null ? 'none' : number_format($need->defenceValue, 0),
            $wall === null ? 'nothing queueable' : $wall->unit->machine_name.' x'.$wall->amount,
            $queued === [] ? 'nothing ordered' : implode(', ', $queued)
        );
    }
}
