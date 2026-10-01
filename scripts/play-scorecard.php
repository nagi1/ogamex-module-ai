<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use OGame\GameMissions\AttackMission;
use OGame\GameMissions\ColonisationMission;
use OGame\GameMissions\EspionageMission;
use OGame\GameMissions\ExpeditionMission;
use OGame\GameMissions\RecycleMission;
use OGame\GameMissions\TransportMission;
use OGame\Models\AllianceApplication;
use OGame\Models\ChatMessage;
use OGame\Models\FleetMission;

/**
 * Does the cohort play like a person? One line per aspect of a real player's day, measured.
 *
 * DEV TOOLING, read-only. Every aspect is a count over the window from the host's own tables, set
 * against a floor that says how often an ordinary active player does it. A missing table is
 * UNMEASURED and fails: a dead lane must never read as a quiet one. The floors below are
 * measurement thresholds for this report, not module policy; nothing in the module reads them.
 *
 *   php Modules/AI/scripts/play-scorecard.php                    the scorecard, last 24h
 *   php Modules/AI/scripts/play-scorecard.php --hours=6          a shorter window
 *   php Modules/AI/scripts/play-scorecard.php --aspect=raids     exit 0 only if that aspect passes
 *   php Modules/AI/scripts/play-scorecard.php --save=ECON-001    also write a snapshot (before/after)
 *   php Modules/AI/scripts/play-scorecard.php --compare=FILE     print the change since a snapshot
 *
 * The last lines are machine-read by the harness: `PLAY: n of m aspects pass` and, when any fail,
 * `PLAY: FAIL name name ...`.
 */

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = scorecard_options(array_slice($argv, 1));
$now = CarbonImmutable::now();
$since = $now->subHours($options['hours']);
$players = AiProfile::query()->where('enabled', true)->pluck('player_id')->map(fn ($id): int => (int) $id)->all();

if ($players === []) {
    echo "PLAY: no enabled AI account in this database — nothing to score\n";

    exit(1);
}

$days = $options['hours'] / 24;
$planets = DB::table('planets')->whereIn('user_id', $players)->pluck('id')->map(fn ($id): int => (int) $id)->all();
$results = [];

foreach (aspects() as $name => [$player, $floorPerAccountDay, $measure, $owner]) {
    $count = $measure($players, $planets, $since);
    $floor = (int) ceil($floorPerAccountDay * count($players) * $days);
    $results[$name] = [
        'count' => $count,
        'floor' => $floor,
        'pass' => $count !== null && $count >= $floor,
        'player' => $player,
        'owner' => $owner,
    ];
}

// Breadth, not only volume: a cohort whose only fleet verb is the expedition plays one game.
$fleet = array_sum(array_map(fn (string $name): int => (int) $results[$name]['count'], ['raids', 'espionage', 'transport', 'recycle', 'expeditions', 'colonisation']));
$monoculture = $fleet === 0 ? 0 : (int) round(100 * (int) $results['expeditions']['count'] / $fleet);
$results['fleet_breadth'] = [
    'count' => $monoculture,
    'floor' => 80,
    'pass' => $fleet > 0 && $monoculture <= 80,
    'player' => 'uses the fleet for more than one thing (expeditions are at most 80% of missions)',
    'owner' => 'app/Domain/Decision/CandidateActionFactory.php',
];

$previous = $options['compare'] !== null && is_file($options['compare'])
    ? (json_decode((string) file_get_contents($options['compare']), true)['aspects'] ?? [])
    : [];

printf("PLAY SCORECARD %s: %d account(s), %d planet(s), last %sh\n", DB::connection()->getDatabaseName(), count($players), count($planets), $options['hours']);

foreach ($results as $name => $result) {
    $value = $result['count'] === null ? 'UNMEASURED' : (string) $result['count'];
    $delta = isset($previous[$name]['count']) && $result['count'] !== null
        ? sprintf(' (%+d)', $result['count'] - (int) $previous[$name]['count'])
        : '';
    printf("  %s %-14s %10s%-8s floor %-6d %s\n", $result['pass'] ? 'PASS' : 'FAIL', $name, $value, $delta, $result['floor'], $result['player']);
    if (!$result['pass']) {
        printf("       owner: %s\n", $result['owner']);
    }
}

if ($options['save'] !== null) {
    $directory = dirname(__DIR__).'/plan/research/ogame/scorecards';
    @mkdir($directory, 0775, true);
    $path = sprintf('%s/%s-%s-%s.json', $directory, $options['save'], DB::connection()->getDatabaseName(), $now->format('Ymd-Hi'));
    file_put_contents($path, json_encode(['at' => $now->toIso8601String(), 'hours' => $options['hours'], 'aspects' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    echo 'snapshot: '.substr($path, strlen(dirname(__DIR__)) + 1)."\n";
}

$failed = array_keys(array_filter($results, fn (array $result): bool => !$result['pass']));
printf("PLAY: %d of %d aspects pass\n", count($results) - count($failed), count($results));
echo $failed === [] ? '' : 'PLAY: FAIL '.implode(' ', $failed)."\n";

if ($options['aspect'] !== null) {
    exit(($results[$options['aspect']]['pass'] ?? false) ? 0 : 1);
}

exit(0);

/**
 * name => [what an ordinary active player does, floor per account per day, measure, owning file].
 *
 * @return array<string, array{0: string, 1: float, 2: callable, 3: string}>
 */
function aspects(): array
{
    return [
        'economy' => ['fills the build queue on every planet each login', 12.0,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => table_count('building_queues', 'planet_id', $planets, $since),
            'app/Domain/Decision/QueueableBuildingPlanner.php'],
        'research' => ['keeps the research lab busy', 2.0,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => table_count('research_queues', 'planet_id', $planets, $since),
            'app/Domain/Decision/QueueableBuildingPlanner.php'],
        'shipyard' => ['builds ships and defence from what the economy leaves', 1.0,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => table_count('unit_queues', 'planet_id', $planets, $since),
            'app/Domain/Decision/QueueableUnitPlanner.php'],
        'espionage' => ['probes neighbours before deciding whom to hit', 1.0,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => missions(EspionageMission::class, $players, $since),
            'app/Domain/Decision/QueueableSpyPlanner.php'],
        'raids' => ['raids a profitable target', 1.0,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => missions(AttackMission::class, $players, $since),
            'app/Domain/Decision/RaidPlanner.php'],
        'transport' => ['moves resources to where they are spent', 0.5,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => missions(TransportMission::class, $players, $since),
            'app/Domain/Decision/QueueableTransferPlanner.php'],
        'recycle' => ['harvests debris it or others left', 0.1,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => missions(RecycleMission::class, $players, $since),
            'app/Domain/Decision/QueueableRecyclePlanner.php'],
        'expeditions' => ['sends expeditions when the fleet has nothing better to do', 0.5,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => missions(ExpeditionMission::class, $players, $since),
            'app/Domain/Decision/QueueableExpeditionPlanner.php'],
        'colonisation' => ['settles new planets while slots remain', 0.05,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => missions(ColonisationMission::class, $players, $since),
            'app/Domain/Decision/QueueableColonyPlanner.php'],
        'fleet_save' => ['saves the fleet before going offline or under attack', 0.5,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => work_done(AiWorkKind::FleetSave, $players, $since),
            'app/Domain/Decision/QueueableFleetSavePlanner.php'],
        'social' => ['answers and starts exchanges with other players', 0.5,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => table_count('ai_social_exchanges', 'player_id', $players, $since),
            'app/Actions/EvaluateAiSocialExchangeAction.php'],
        'chat' => ['writes to other players', 0.5,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => model_count(ChatMessage::class, 'sender_id', $players, $since),
            'app/Actions/EvaluateAiSocialExchangeAction.php'],
        'alliance' => ['applies to, recruits for or leaves an alliance', 0.05,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => model_count(AllianceApplication::class, 'user_id', $players, $since),
            'app/Actions/ReviewAiAllianceApplicationsAction.php'],
        'work_failures' => ['rarely has an order refused (failed work items stay under 20% of finished ones)', 0.0,
            fn (array $players, array $planets, CarbonImmutable $since): ?int => failure_headroom($players, $since),
            'app/Actions/ExecuteAiIntentAction.php'],
    ];
}

/** Rows created in the window, or null when the host has no such table. */
function table_count(string $table, string $column, array $ids, CarbonImmutable $since): ?int
{
    if (!Schema::hasTable($table)) {
        return null;
    }

    return DB::table($table)->whereIn($column, $ids)->where('created_at', '>=', $since)->count();
}

/** @param class-string $model */
function model_count(string $model, string $column, array $ids, CarbonImmutable $since): ?int
{
    return class_exists($model) ? table_count((new $model())->getTable(), $column, $ids, $since) : null;
}

/**
 * Missions the cohort launched, by the host's own mission type id.
 *
 * @param class-string $mission
 */
function missions(string $mission, array $players, CarbonImmutable $since): ?int
{
    if (!class_exists($mission) || !Schema::hasTable((new FleetMission())->getTable())) {
        return null;
    }

    return FleetMission::query()->whereIn('user_id', $players)->where('mission_type', $mission::getTypeId())
        ->where('created_at', '>=', $since)->count();
}

function work_done(AiWorkKind $kind, array $players, CarbonImmutable $since): int
{
    return DB::table('ai_work_items')->whereIn('player_id', $players)->where('kind', $kind->value)
        ->where('state', AiWorkState::Completed->value)->where('updated_at', '>=', $since)->count();
}

/**
 * Percentage points left under the 20% failure ceiling, so the floor of 0 reads the same way as
 * every other aspect: a negative number fails.
 */
function failure_headroom(array $players, CarbonImmutable $since): int
{
    $finished = DB::table('ai_work_items')->whereIn('player_id', $players)->where('updated_at', '>=', $since)
        ->whereIn('state', [AiWorkState::Completed->value, AiWorkState::Failed->value])
        ->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
    $total = (int) $finished->sum();

    return $total === 0 ? -1 : 20 - (int) round(100 * (int) ($finished[AiWorkState::Failed->value] ?? 0) / $total);
}

/** @return array{hours: float, aspect: ?string, save: ?string, compare: ?string} */
function scorecard_options(array $arguments): array
{
    $options = ['hours' => 24.0, 'aspect' => null, 'save' => null, 'compare' => null];

    foreach ($arguments as $argument) {
        if (preg_match('/^--(hours|aspect|save|compare)=(.+)$/', $argument, $match) !== 1) {
            fwrite(STDERR, "unknown argument [{$argument}]\n");

            exit(2);
        }
        // Down to six minutes: the harness reads a short window for an early pass, never for a fail.
        $options[$match[1]] = $match[1] === 'hours' ? max(0.1, (float) $match[2]) : $match[2];
    }

    return $options;
}
