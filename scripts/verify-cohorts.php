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
// A colony founded this hour has no shipyard and a few hundred metal: a player builds mines first and
// walls it once the yard exists, so a planet is only counted bare after that lead time.
$planetQuery->where('created_at', '<=', now()->subHour());
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
// which is information about the universe, not a defect of the account, and is printed below for the
// accounts the stored state could not already clear.
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

    // At 1000x a build ends in minutes, so a planet read between its last build ending and the
    // account's next turn is waiting, not neglected. Idle means the account finished a session
    // after the planet's last build ended and the planet still has a legal step and no build.
    $lastSession = (int) strtotime((string) DB::table('ai_work_items')->where('player_id', $accountId)
        ->where('kind', AiWorkKind::RunSession->value)->where('state', AiWorkState::Completed->value)->max('updated_at'));
    // A build ordered around the last session is that session's work: its orders run minutes after it
    // and finish within seconds, so the planet is idle only when a session passed with no order for it.
    $lastBuildEnd = BuildingQueue::query()->whereIn('planet_id', $ownPlanets)->where('canceled', 0)
        ->groupBy('planet_id')->selectRaw('planet_id, max(greatest(time_end, time_start + 300)) as ended')->pluck('ended', 'planet_id');
    // The planner costs about a second per account and is the whole price of this read, so it is asked
    // only about an account the stored state cannot already clear: more than the idle share of its
    // planets must be free of a build, an order and a newer session than their last build.
    $waiting = array_filter($ownPlanets, static fn (int $id): bool => !in_array($id, $busyPlanets, true)
        && !in_array($id, $ordered, true)
        && $lastSession > (int) ($lastBuildEnd[$id] ?? 0));
    if (count($waiting) / count($ownPlanets) <= $IDLE_SHARE) {
        continue;
    }

    $player = app(PlayerServiceFactory::class)->make($accountId, true);
    // "Buildable" means the building planner has a *building* step for the planet. A step that is a
    // technology belongs to the account's one lab, not to this planet's build queue: the account is
    // researching, which is play, and the lab step carries its planet's id, so an account researching
    // read as buildable with an empty build queue on every pass.
    $planned = array_values(array_map(
        static fn ($step): int => $step->planetId,
        array_filter($buildingPlanner->steps($accountId, $player), static fn ($step): bool => $step instanceof QueueableBuilding),
    ));
    $idle = array_filter($waiting, static fn (int $id): bool => in_array($id, $planned, true));

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

// Authenticity (AUTH-001): what a neighbour or an operator could observe about the cohort as a
// crowd, from account-authenticity.md. Each signature is read from state the host or the module
// already keeps, over the last seven days. They are measurement thresholds for this log, like the
// quality ones above, and a failing signature is raised like any other invariant.
//
//   AUTH_UPTIME      an account whose finished sessions cover this many hours of the day (the host's own
//                    round-the-clock flag is 18) never sleeps
//   AUTH_REPETITION  an account whose most common five-action run is this share of its recent actions
//   AUTH_SAVE        a cohort that has saved this many fleets and never once lost one
//   AUTH_CONTACT     a cohort in which fewer than this share of accounts ever wrote to another player
//   AUTH_GROWTH      an account whose public score is this many times the cohort median, or a tenth of it
$AUTH_UPTIME_HOURS = 18;
$AUTH_REPEAT_SHARE = 0.5;
$AUTH_SAVES_WITHOUT_LOSS = 20;
$AUTH_CONTACT_SHARE = 0.25;
$AUTH_GROWTH_RATIO = 10;
$week = now()->subDays(7);

$authUptime = [];
$sessions = DB::table('ai_work_items')->whereIn('player_id', $playerIds)->where('kind', AiWorkKind::RunSession->value)
    ->where('state', AiWorkState::Completed->value)->where('updated_at', '>=', $week)
    ->selectRaw('player_id, count(distinct hour(updated_at)) as hours')->groupBy('player_id')->get();
foreach ($sessions as $row) {
    if ((int) $row->hours >= $AUTH_UPTIME_HOURS) {
        $authUptime[] = sprintf('player %d is active in %d of 24 hours of the day', $row->player_id, $row->hours);
    }
}

$authRepetition = [];
foreach ($playerIds as $accountId) {
    $kinds = DB::table('ai_work_items')->where('player_id', $accountId)->where('kind', '!=', AiWorkKind::RunSession->value)
        ->orderByDesc('id')->limit(300)->pluck('kind')->all();
    if (count($kinds) < 50) {
        continue;
    }
    $grams = [];
    for ($i = 0; $i + 5 <= count($kinds); $i++) {
        $gram = implode(',', array_slice($kinds, $i, 5));
        $grams[$gram] = ($grams[$gram] ?? 0) + 1;
    }
    $share = max($grams) / array_sum($grams);
    if ($share > $AUTH_REPEAT_SHARE) {
        $authRepetition[] = sprintf('player %d repeats one five-action run for %.0f%% of its recent actions', $accountId, $share * 100);
    }
}

$authSave = [];
$saves = DB::table('ai_work_items')->whereIn('player_id', $playerIds)->where('kind', AiWorkKind::FleetSave->value)->where('updated_at', '>=', $week);
$savesTotal = (clone $saves)->count();
// A save is lost when its work failed or when the account chose not to take it (the stop counter).
$savesLost = (int) DB::table('ai_stop_counters')->where('reason', 'save_lost')->where('observed_on', '>=', $week->toDateString())->sum('occurrences');
$savesFailed = (clone $saves)->where('state', AiWorkState::Failed->value)->count() + $savesLost;
$savesTotal += $savesLost;
if ($savesTotal >= $AUTH_SAVES_WITHOUT_LOSS && $savesFailed === 0) {
    $authSave[] = sprintf('%d fleet saves in a week and none ever failed: a human loses one now and then', $savesTotal);
}

$authContact = [];
if (Schema::hasTable('chat_messages')) {
    $talkers = DB::table('chat_messages')->whereIn('sender_id', $playerIds)->where('created_at', '>=', $week)
        ->whereColumn('recipient_id', '!=', 'sender_id')->distinct()->count('sender_id');
    if ($talkers / count($playerIds) < $AUTH_CONTACT_SHARE) {
        $authContact[] = sprintf('only %d of %d accounts wrote to another player this week', $talkers, count($playerIds));
    }
}

$authGrowth = [];
// Growth is compared among accounts that have played for days: an account seeded this hour has a
// score of nothing, and measuring it against its elders reads a young cohort as an abnormal one.
$seasoned = DB::table('ai_work_items')->whereIn('player_id', $playerIds)->groupBy('player_id')
    ->havingRaw('min(created_at) < ?', [now()->subDays(3)])->pluck('player_id')->all();
$scores = DB::table('highscores')->whereIn('player_id', $seasoned)->pluck('general', 'player_id')->map(fn ($v): float => (float) $v)->all();
if (count($scores) >= 5) {
    $sorted = array_values($scores);
    sort($sorted);
    $median = $sorted[intdiv(count($sorted), 2)];
    foreach ($scores as $accountId => $score) {
        if ($median > 0 && ($score > $median * $AUTH_GROWTH_RATIO || $score < $median / $AUTH_GROWTH_RATIO)) {
            $authGrowth[] = sprintf('player %d scores %s against a cohort median of %s', $accountId, number_format($score), number_format($median));
        }
    }
}

// Named invariants, one line per violation, and a machine-readable verdict at the end. The harness
// reads that line to raise a task for anything the cohorts fail, so a repeated "QUALITY: FAIL" is not
// a dead end someone has to notice by eye.
// What an administrator sees in the universe: players fight each other and moons appear. A day of raids
// that only pillage empty planets has no combat rounds, no debris and no moons, which reads as a dead
// universe however many missions ran.
//   LIFE_FIGHTS  at least this share of the day's battles has a defender and combat rounds
//   LIFE_MOONS   a universe with this many battles in a day and no moon at all
$LIFE_FIGHT_SHARE = 0.2;
$LIFE_MOON_BATTLES = 100;
$lifeFights = [];
$lifeMoons = [];
$dayBattles = DB::table('battle_reports')->where('created_at', '>=', now()->subDay())->get(['rounds']);
if ($dayBattles->count() >= $LIFE_MOON_BATTLES) {
    $fought = $dayBattles->filter(fn ($row): bool => count(json_decode((string) $row->rounds, true) ?: []) > 0)->count();
    if ($fought / $dayBattles->count() < $LIFE_FIGHT_SHARE) {
        $lifeFights[] = sprintf('%d of %d battles today had combat rounds (%.0f%%): the raids pillage empty planets instead of fighting', $fought, $dayBattles->count(), 100 * $fought / $dayBattles->count());
    }
    if (DB::table('planets')->where('planet_type', 3)->count() === 0) {
        $lifeMoons[] = sprintf('%d battles today and not one moon in the universe', $dayBattles->count());
    }
}

//   LIFE_CAPITAL  under a tenth of the accounts own a military hull dearer than the median military hull: the host's own
//                 catalogue says which that is, so a mod-added capital ship counts with no edit here
$lifeCapital = [];
$military = collect(ObjectService::getMilitaryShipObjects())->filter(fn ($ship): bool => $ship->machine_name !== 'espionage_probe');
if ($military->count() >= 3) {
    $priceOf = fn ($ship): float => (float) ($ship->price->resources->metal->get() + $ship->price->resources->crystal->get() + $ship->price->resources->deuterium->get());
    $prices = $military->map($priceOf)->sort()->values();
    $median = $prices[intdiv($prices->count(), 2)];
    $capital = $military->filter(fn ($ship): bool => $priceOf($ship) > $median)->map(fn ($ship): string => '`'.$ship->machine_name.'`');
    $owners = DB::table('planets')->whereIn('user_id', $playerIds)->whereRaw($capital->map(fn (string $column): string => "$column > 0")->implode(' or '))->distinct()->count('user_id');
    if ($owners / count($playerIds) < 0.1) {
        $lifeCapital[] = sprintf('%d of %d accounts own a military hull dearer than the median (%s): there is no war fleet', $owners, count($playerIds), $capital->implode(', '));
    }
}

$invariants = [
    'LIFE_CAPITAL' => $lifeCapital,
    'LIFE_FIGHTS' => $lifeFights,
    'LIFE_MOONS' => $lifeMoons,
    'NAKED_BESIDE_WALLED' => $nakedBesideWalled,
    'WALL_CEILING' => $overCeiling,
    'ALLIANCE_SHARE' => $allianceShare,
    'IDLE_QUEUES' => $idleQueues,
    'UNIVERSE_SPEED' => $tooFast,
    'AUTH_UPTIME' => $authUptime,
    'AUTH_REPETITION' => $authRepetition,
    'AUTH_SAVE' => $authSave,
    'AUTH_CONTACT' => $authContact,
    'AUTH_GROWTH' => $authGrowth,
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

foreach (array_filter(array_keys($invariants), fn (string $name): bool => str_starts_with($name, 'AUTH_')) as $name) {
    echo 'AUTHENTICITY: '.($invariants[$name] === [] ? 'PASS' : 'FAIL').' '.$name."\n";
}

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
