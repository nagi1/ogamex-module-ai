<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Modules\AI\Actions\EvaluateAiSocialExchangeAction;
use Modules\AI\Actions\RecordAiMemoryFactAction;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
use Modules\AI\Enums\AiCognitionMode;
use Modules\AI\Enums\AiMemoryDriver;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiMemoryFact;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\LongTermMemorySelector;

/**
 * Real-adapter approval test for the AgentOS memory driver: does its ranking change anything
 * the native path does, and by how much, on real rows and the real sidecar?
 *
 * This is the Gate 2 ("demonstrated value") measurement for `ai.cognition.memory.driver=agentos`.
 * It is an operator run, not a test: it writes real rows, calls the real sidecar over HTTP
 * through the module's own adapter, drives the real decision action, and deletes what it wrote.
 *
 * Run inside the application container:
 *
 *   php scripts/e2e-agentos-recall-benchmark.php --confirm
 *   php scripts/e2e-agentos-recall-benchmark.php --confirm --trials=10 --facts=30
 *
 * What it measures, per trial:
 *   - whether the fact the decision needs (a live `ResourceDebt`) survives the 20-fact cut,
 *     under native recency and under the driver's ranking;
 *   - whether the newest fact still survives (the "no worsened current-fact correctness" bar);
 *   - whether the driver actually contacts the sidecar with the production wiring (no query
 *     text is sent by `EvaluateAiSocialExchangeAction`, so the driver must return the native
 *     order — this is asserted, not assumed);
 *   - the real `HelpRequest` answer from `EvaluateAiSocialExchangeAction`, under both drivers,
 *     at the amount production passes (0) and at an amount the debt penalty can change.
 *
 * Honest limits, stated up front so the numbers are not read as more than they are:
 *   - The corpus is written through the module's own recorder but the observation rows are not
 *     created, so `source_type` resolves to null. It is not used by ranking or by the predicate.
 *   - Only two predicates exist in the module (`AllianceMembership`, `ResourceDebt`), so this
 *     measures lexical relevance over a two-kind fact universe, not semantic recall.
 *   - The driver's query text is derived from the exchange the way a caller would derive it
 *     (type name plus terms). A caller that sends no text gets no ranking at all.
 */

const BENCH_PLAYER = 990_001;
const BENCH_SUBJECT = 990_002;
/** Mirrors `EvaluateAiSocialExchangeAction::HISTORY_RECALL_LIMIT`. */
const RECALL_LIMIT = 20;
/** What `RunAiConversationCycleAction::respond()` passes: no transfer capability is wired. */
const PRODUCTION_AVAILABLE_AMOUNT = 0.0;
/** An amount the request can actually be met with, so the debt penalty is reachable. */
const REACHABLE_AVAILABLE_AMOUNT = 10_000.0;
const BENCH_RESOURCE = 'metal';
const BENCH_AMOUNT = 5000;
const BENCH_ALLIANCE_ID = 500_123;

$options = arguments($argv);

if (!$options['confirm']) {
    fwrite(STDERR, "usage: e2e-agentos-recall-benchmark.php --confirm [--trials=6] [--facts=30]\n");

    exit(2);
}

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "refusing to write benchmark rows in production\n");

    exit(2);
}

$trials = max(1, (int) $options['trials']);
$facts = max(21, (int) $options['facts']);

printf("=== AgentOS memory driver approval test (real stack) ===\n");
printf("sidecar: %s   facts per counterparty: %d   trials: %d\n", config('ai.cognition.memory.agentos.base_url'), $facts, $trials);
printf("recall limit: %d   production amount: %s   reachable amount: %s\n\n", RECALL_LIMIT, PRODUCTION_AVAILABLE_AMOUNT, REACHABLE_AVAILABLE_AMOUNT);

cleanup();
writeRelationship();
$results = [];

try {
    for ($trial = 1; $trial <= $trials; $trial++) {
        // Trials must not see each other's facts, or the cut and the leak count measure the
        // wrong corpus.
        AiMemoryFact::query()->where('player_id', BENCH_PLAYER)->delete();
        // The debt walks the whole corpus, so the cut is exercised above and below its limit.
        $debtPosition = 1 + (int) round(($trial - 1) * ($facts - 1) / max(1, $trials - 1));

        $results[] = measureTrial($trial, writeCorpus($facts, $debtPosition), $debtPosition);
    }
} finally {
    cleanup();
}

report($results, $facts);

/**
 * One trial: recall twice over the same corpus, then answer the same help request twice.
 *
 * @param  array<int, int>  $corpus  fact id by recency position, 1 = newest
 * @return array<string, mixed>
 */
function measureTrial(int $trial, array $corpus, int $debtPosition): array
{
    $now = CarbonImmutable::now();
    $queryText = 'HelpRequest '.BENCH_RESOURCE.' '.BENCH_AMOUNT;

    $native = recall(AiMemoryDriver::Native, $now, $queryText, AiCognitionMode::Native);
    $external = recall(AiMemoryDriver::AgentOs, $now, $queryText, AiCognitionMode::External);
    $hybrid = recall(AiMemoryDriver::AgentOs, $now, $queryText, AiCognitionMode::Hybrid);
    // The production wiring: `EvaluateAiSocialExchangeAction` sends no query text, so the
    // adapter has nothing to rank against and must fall through to native order.
    $unwired = recall(AiMemoryDriver::AgentOs, $now, null, AiCognitionMode::External);

    $exchange = createExchange();

    $measured = [
        'trial' => $trial,
        'debt_position' => $debtPosition,
        'native' => summary($native, $corpus),
        'external' => summary($external, $corpus),
        'hybrid' => summary($hybrid, $corpus),
        'unwired_matches_native' => ids($unwired) === ids($native),
        'production' => decide($exchange, PRODUCTION_AVAILABLE_AMOUNT, $now, AiCognitionMode::External),
        'reachable' => decide($exchange, REACHABLE_AVAILABLE_AMOUNT, $now, AiCognitionMode::External),
        'hybrid_reachable' => decide($exchange, REACHABLE_AVAILABLE_AMOUNT, $now, AiCognitionMode::Hybrid),
        'wired_reachable' => wiredDecision($exchange, REACHABLE_AVAILABLE_AMOUNT, $now, $queryText),
    ];

    printf(
        "trial %-3d debt_at=%-3d | native debt=%-3s newest=%s | external debt=%-3s newest=%s | hybrid debt=%-3s newest=%s | prod %s vs %s | reach %s vs %s | hybrid %s vs %s\n",
        $trial,
        $debtPosition,
        yes($measured['native']['debt_in_cut']),
        yes($measured['native']['newest_in_cut']),
        yes($measured['external']['debt_in_cut']),
        yes($measured['external']['newest_in_cut']),
        yes($measured['hybrid']['debt_in_cut']),
        yes($measured['hybrid']['newest_in_cut']),
        $measured['production']['native'],
        $measured['production']['agentos'],
        $measured['reachable']['native'],
        $measured['reachable']['agentos'],
        $measured['hybrid_reachable']['native'],
        $measured['hybrid_reachable']['agentos'],
    );

    return $measured;
}

/**
 * The same decision with a caller that does supply a query text.
 *
 * This is the experiment's own override, not module behaviour: `EvaluateAiSocialExchangeAction`
 * sends no text today, so the only way to measure what wiring it would buy is to supply the text
 * here and bind the contract to it. The override exists so the "would fixing the caller help?"
 * question gets a number instead of a guess, and it is restored immediately afterwards.
 *
 * @return array<string, string>
 */
function wiredDecision(AiSocialExchange $exchange, float $availableAmount, CarbonImmutable $now, string $queryText): array
{
    app()->bind(LongTermMemory::class, static fn (): LongTermMemory => new class ($queryText) implements LongTermMemory {
        public function __construct(private readonly string $queryText)
        {
        }

        /** @return list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}> */
        public function recallRelevantMemories(MemoryRecallQuery $query): array
        {
            return app(LongTermMemorySelector::class)->resolve()->recallRelevantMemories(app()->makeWith(MemoryRecallQuery::class, [
                'playerId' => $query->playerId,
                'subjectPlayerId' => $query->subjectPlayerId,
                'now' => $query->now,
                'limit' => $query->limit,
                'queryText' => $this->queryText,
            ]));
        }
    });

    $answers = decide($exchange, $availableAmount, $now, AiCognitionMode::External);

    app()->bind(LongTermMemory::class, fn (): LongTermMemory => app(LongTermMemorySelector::class)->resolve());

    return $answers;
}

/**
 * @return array{recalled: list<array{id:int,source_observation_id:int,source_type:string|null,source_id:int|null,subject_player_id:int,predicate:string,evidence_kind:string,speaker_player_id:int|null,value:array<string,mixed>}>, milliseconds: float}
 */
function recall(AiMemoryDriver $driver, CarbonImmutable $now, string|null $queryText, AiCognitionMode $mode): array
{
    config(['ai.cognition.memory.driver' => $driver->value, 'ai.cognition.mode' => $mode->value]);

    $query = app()->makeWith(MemoryRecallQuery::class, [
        'playerId' => BENCH_PLAYER,
        'subjectPlayerId' => BENCH_SUBJECT,
        'now' => $now,
        'limit' => RECALL_LIMIT,
        'queryText' => $queryText,
    ]);

    $started = microtime(true);
    $recalled = app(LongTermMemorySelector::class)->resolve()->recallRelevantMemories($query);
    $elapsed = (microtime(true) - $started) * 1000;

    return ['recalled' => $recalled, 'milliseconds' => $elapsed];
}

/**
 * @param  array{recalled: array<int, array<string, mixed>>, milliseconds: float}  $recall
 * @param  array<int, int>  $corpus
 * @return array<string, mixed>
 */
function summary(array $recall, array $corpus): array
{
    $recalled = $recall['recalled'];
    $ids = ids($recall);
    $predicates = [];

    foreach ($recalled as $memory) {
        $predicates[] = $memory['predicate'];
    }

    return [
        'ids' => $ids,
        'count' => count($ids),
        'milliseconds' => $recall['milliseconds'],
        'debt_in_cut' => in_array(AiMemoryPredicate::ResourceDebt->name, $predicates, true),
        // The newest fact is position 1; the bar is that a ranking does not hide current facts.
        'newest_in_cut' => isset($corpus[1]) && in_array($corpus[1], $ids, true),
        'leaked' => array_diff($ids, array_values($corpus)) !== [],
    ];
}

/**
 * The same help request answered under each driver, at one available amount.
 *
 * @return array<string, string>
 */
function decide(AiSocialExchange $exchange, float $availableAmount, CarbonImmutable $now, AiCognitionMode $mode): array
{
    $answers = [];

    foreach ([AiMemoryDriver::Native, AiMemoryDriver::AgentOs] as $driver) {
        config(['ai.cognition.memory.driver' => $driver->value, 'ai.cognition.mode' => $mode->value]);
        // Written straight through the builder: the in-memory model still holds the values it
        // was created with, so an `update()` on it would find nothing dirty and issue no query.
        AiSocialExchange::query()->whereKey($exchange->id)->update([
            'state' => AiSocialExchangeState::Proposed,
            'response' => null,
            'response_terms' => null,
            'response_reason' => null,
            'responded_at' => null,
        ]);

        $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, $availableAmount, $now);
        $answers[$driver->value] = ($evaluated?->response?->value ?? 'none').':'.($evaluated?->response_reason?->value ?? 'none');
    }

    $answers['same'] = ($answers['native'] === $answers['agentos']) ? 'yes' : 'no';

    return $answers;
}

/**
 * Facts for one counterparty, newest first, with the debt at `$debtPosition`.
 *
 * @return array<int, int> fact id by recency position
 */
function writeCorpus(int $facts, int $debtPosition): array
{
    $now = CarbonImmutable::now();
    $corpus = [];

    for ($position = 1; $position <= $facts; $position++) {
        $isDebt = $position === $debtPosition;

        $fact = app(RecordAiMemoryFactAction::class)->handle(
            BENCH_PLAYER,
            BENCH_SUBJECT,
            $isDebt ? AiMemoryPredicate::ResourceDebt : AiMemoryPredicate::AllianceMembership,
            $isDebt ? AiMemoryEvidenceKind::Claimed : AiMemoryEvidenceKind::Verified,
            $isDebt
                ? [AiSocialTerm::Resource->value => BENCH_RESOURCE, AiSocialTerm::Amount->value => BENCH_AMOUNT]
                : ['alliance_id' => BENCH_ALLIANCE_ID],
            observationId(),
            $now->subMinutes($position),
            speakerPlayerId: $isDebt ? BENCH_SUBJECT : null,
        );

        $corpus[$position] = (int) $fact->id;
    }

    return $corpus;
}

/** Standing high enough that the debt penalty flips the answer rather than adding to a refusal. */
function writeRelationship(): void
{
    AiRelationship::query()->updateOrCreate(
        ['player_id' => BENCH_PLAYER, 'other_player_id' => BENCH_SUBJECT],
        ['trust' => 0.5, 'affinity' => 0.2, 'threat' => 0, 'respect' => 0, 'social_importance' => 0, 'revision' => 1],
    );
}

function createExchange(): AiSocialExchange
{
    return AiSocialExchange::query()->create([
        'player_id' => BENCH_PLAYER,
        'counterparty_player_id' => BENCH_SUBJECT,
        'source_observation_id' => observationId(),
        'type' => AiSocialExchangeType::HelpRequest,
        'terms' => [AiSocialTerm::Resource->value => BENCH_RESOURCE, AiSocialTerm::Amount->value => BENCH_AMOUNT],
        'state' => AiSocialExchangeState::Proposed,
        'revision' => 1,
    ]);
}

/** Ids above the real observation range, so a benchmark run can never collide with recorded play. */
function observationId(): int
{
    static $next = 9_900_000;

    return ++$next;
}

/**
 * @param  array{recalled: array<int, array<string, mixed>>}  $recall
 * @return list<int>
 */
function ids(array $recall): array
{
    return array_map(static fn (array $memory): int => (int) $memory['id'], $recall['recalled']);
}

function cleanup(): void
{
    AiMemoryFact::query()->where('player_id', BENCH_PLAYER)->delete();
    AiSocialExchange::query()->where('player_id', BENCH_PLAYER)->delete();
    AiCommitment::query()->where('player_id', BENCH_PLAYER)->delete();
    AiRelationship::query()->where('player_id', BENCH_PLAYER)->delete();
}

/**
 * @param  array<int, array<string, mixed>>  $results
 */
function report(array $results, int $facts): void
{
    $trials = count($results);
    $nativeDebt = count(array_filter($results, static fn (array $trial): bool => $trial['native']['debt_in_cut']));
    $externalDebt = count(array_filter($results, static fn (array $trial): bool => $trial['external']['debt_in_cut']));
    $hybridDebt = count(array_filter($results, static fn (array $trial): bool => $trial['hybrid']['debt_in_cut']));
    $orderChangedExternal = count(array_filter($results, static fn (array $trial): bool => $trial['native']['ids'] !== $trial['external']['ids']));
    $orderChangedHybrid = count(array_filter($results, static fn (array $trial): bool => $trial['native']['ids'] !== $trial['hybrid']['ids']));
    $unwired = count(array_filter($results, static fn (array $trial): bool => $trial['unwired_matches_native']));
    $newestNative = count(array_filter($results, static fn (array $trial): bool => $trial['native']['newest_in_cut']));
    $newestExternal = count(array_filter($results, static fn (array $trial): bool => $trial['external']['newest_in_cut']));
    $newestHybrid = count(array_filter($results, static fn (array $trial): bool => $trial['hybrid']['newest_in_cut']));
    $leaks = count(array_filter($results, static fn (array $trial): bool => $trial['external']['leaked'] || $trial['hybrid']['leaked'] || $trial['native']['leaked']));

    $productionSame = count(array_filter($results, static fn (array $trial): bool => $trial['production']['same'] === 'yes'));
    $reachableSame = count(array_filter($results, static fn (array $trial): bool => $trial['reachable']['same'] === 'yes'));
    $hybridSame = count(array_filter($results, static fn (array $trial): bool => $trial['hybrid_reachable']['same'] === 'yes'));
    $wiredSame = count(array_filter($results, static fn (array $trial): bool => $trial['wired_reachable']['same'] === 'yes'));

    $deltaExternal = percentage($externalDebt, $trials) - percentage($nativeDebt, $trials);
    $deltaHybrid = percentage($hybridDebt, $trials) - percentage($nativeDebt, $trials);
    $latencyNative = median(array_column(array_column($results, 'native'), 'milliseconds'));
    $latencyExternal = median(array_column(array_column($results, 'external'), 'milliseconds'));

    printf("\n--- summary ---\n");
    printf("required-fact recall at the %d-fact cut: native %5.1f%%, external %5.1f%% (%+.1fpp), hybrid %5.1f%% (%+.1fpp)\n", RECALL_LIMIT, percentage($nativeDebt, $trials), percentage($externalDebt, $trials), $deltaExternal, percentage($hybridDebt, $trials), $deltaHybrid);
    printf("newest fact kept in the cut: native %d/%d, external %d/%d, hybrid %d/%d\n", $newestNative, $trials, $newestExternal, $trials, $newestHybrid, $trials);
    printf("order differs from native: external %d/%d trials, hybrid %d/%d trials\n", $orderChangedExternal, $trials, $orderChangedHybrid, $trials);
    printf("scope leaks (an id the module never sent): %d\n", $leaks);
    printf("recall latency p50: native %.1fms, external %.1fms (hybrid uses the same sidecar)\n", $latencyNative, $latencyExternal);
    printf("production wiring (availableAmount %s): no query text, unwired order equals native in %d/%d trials\n", PRODUCTION_AVAILABLE_AMOUNT, $unwired, $trials);
    printf("decision parity at the production amount: %d/%d identical (%s)\n", $productionSame, $trials, implode(', ', array_unique(array_map(static fn (array $trial): string => $trial['production']['native'], $results))));
    printf("decision parity at a reachable amount (external): %d/%d identical (%s)\n", $reachableSame, $trials, implode(' | ', array_unique(array_map(static fn (array $trial): string => $trial['reachable']['native'].' -> '.$trial['reachable']['agentos'], $results))));
    printf("decision parity at a reachable amount (hybrid): %d/%d identical (%s)\n", $hybridSame, $trials, implode(' | ', array_unique(array_map(static fn (array $trial): string => $trial['hybrid_reachable']['native'].' -> '.$trial['hybrid_reachable']['agentos'], $results))));
    printf("decision parity with a caller-supplied query text (external): %d/%d identical (%s)\n", $wiredSame, $trials, implode(' | ', array_unique(array_map(static fn (array $trial): string => $trial['wired_reachable']['native'].' -> '.$trial['wired_reachable']['agentos'], $results))));
    printf("corpus: %d facts per counterparty (the cut only bites above %d)\n", $facts, RECALL_LIMIT);

    printf("\njson:%s\n", json_encode([
        'trials' => $trials,
        'facts_per_counterparty' => $facts,
        'recall_limit' => RECALL_LIMIT,
        'required_fact_recall' => ['native' => percentage($nativeDebt, $trials), 'external' => percentage($externalDebt, $trials), 'hybrid' => percentage($hybridDebt, $trials)],
        'newest_fact_kept' => ['native' => $newestNative, 'external' => $newestExternal, 'hybrid' => $newestHybrid],
        'order_differs_from_native' => ['external' => $orderChangedExternal, 'hybrid' => $orderChangedHybrid],
        'scope_leaks' => $leaks,
        'latency_ms_p50' => ['native' => round($latencyNative, 3), 'external' => round($latencyExternal, 3)],
        'unwired_matches_native_trials' => $unwired,
        'decision_parity' => ['production_amount' => $productionSame, 'external_reachable' => $reachableSame, 'hybrid_reachable' => $hybridSame, 'external_with_query_text' => $wiredSame],
        'production_amount' => PRODUCTION_AVAILABLE_AMOUNT,
        'reachable_amount' => REACHABLE_AVAILABLE_AMOUNT,
    ], JSON_UNESCAPED_SLASHES));
}

/** @param array<int, float> $values */
function median(array $values): float
{
    if ($values === []) {
        return 0.0;
    }

    sort($values);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
}

function percentage(int $part, int $total): float
{
    return $total === 0 ? 0.0 : round($part * 100 / $total, 1);
}

function yes(bool $value): string
{
    return $value ? 'yes' : 'no';
}

/** @param list<string> $argv @return array{confirm: bool, trials: int, facts: int} */
function arguments(array $argv): array
{
    $options = ['confirm' => false, 'trials' => 6, 'facts' => 30];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--confirm') {
            $options['confirm'] = true;
        }

        if (str_starts_with($argument, '--trials=')) {
            $options['trials'] = (int) substr($argument, 9);
        }

        if (str_starts_with($argument, '--facts=')) {
            $options['facts'] = (int) substr($argument, 8);
        }
    }

    return $options;
}
