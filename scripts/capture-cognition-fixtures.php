<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Support\FatimaScenarioTemplate;

/**
 * Records what the cognition sidecars answer, verbatim, into the fixture files the driver
 * feature tests replay.
 *
 *   php scripts/capture-cognition-fixtures.php --confirm
 *
 * Why this exists: the driver tests must exercise the module's parsers against the real
 * wire format. A response literal hand-written in a test can drift from what the driver
 * actually sends, and the suite then stays green while production parsing breaks — a
 * literal naming features the retriever cannot represent hid exactly that.
 * Capturing the real bodies once, into `tests/Fixtures/cognition/`, and replaying them
 * through `Http::fake` afterwards keeps the tests offline and deterministic without
 * inventing the payload shape.
 *
 * The payloads are the ones the module's own clients send, so the recording is faithful:
 *   - FAtiMA:  POST /scenarios, POST {char}/beliefs, POST {char}/perceptions,
 *              GET {char}/emotions, POST {char}/socialexchanges
 *   - CBRKit:  POST /retrieve
 *   - AgentOS: POST /recall
 *
 * Every recording carries the request that produced it, so a test's fake can prove the
 * request it received is the one the recording answers instead of the fixture being read
 * as an unconditional answer.
 *
 * Run inside the application container with the cognition stack up:
 *
 *   docker compose -f Modules/AI/docker/cognition/docker-compose.yml up -d
 *   docker compose -f local-docker-dev/docker-compose.yml exec ogamex-app \
 *     php Modules/AI/scripts/capture-cognition-fixtures.php --confirm
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "this rewrites tests/Fixtures/cognition/*.json from live sidecars; pass --confirm\n");

    exit(2);
}

$directory = dirname(__DIR__).'/tests/Fixtures/cognition';

foreach (['fatima' => captureFatima(), 'cbrkit' => captureCbrKit(), 'agentos' => captureAgentOs()] as $driver => $fixture) {
    $path = $directory.'/'.$driver.'.json';
    // Pretty printed and newline terminated so a re-capture diff is reviewable.
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

    printf("%-8s %d channels -> %s\n", $driver, count($fixture['channels']), $path);
}

/** @return array{_meta: array<string, mixed>, channels: array<string, mixed>} */
function captureFatima(): array
{
    $scenario = 'OgameCognition';
    $instance = 1;
    $character = AiArchetype::Miner->name;
    $base = config('ai.cognition.fatima.base_url');
    $template = app(FatimaScenarioTemplate::class);
    $scenarioJson = $template->scenarioJson();
    $assetsJson = $template->assetsJson();
    $characterPath = sprintf('/scenarios/%s/instances/%d/characters/%s', $scenario, $instance, $character);

    $post = static fn (string $path, array $body): Response => Http::baseUrl($base)->timeout(15)->acceptJson()->post($path, $body);
    $get = static fn (string $path): Response => Http::baseUrl($base)->timeout(15)->acceptJson()->get($path);
    $reset = static fn () => $post('/scenarios', ['scenario' => $scenarioJson, 'assets' => $assetsJson]);

    // The three mutating calls, recorded once each so their acknowledgement text is real.
    $channels = [
        'scenario_created' => $post('/scenarios', ['scenario' => $scenarioJson, 'assets' => $assetsJson])->json(),
        'belief_updated' => $post($characterPath.'/beliefs', ['name' => 'StimulusDesirability(SELF, Other)', 'value' => '-0.4'])->json(),
        'perception_perceived' => $post($characterPath.'/perceptions', [fatimaEvent('Harm')])->json(),
    ];

    // One appraisal per signed OCC belief pair the affect engine can send: reload the authored
    // scenario, write both beliefs, perceive the branch's event, then read the emotion pool.
    $emotions = [];

    foreach (fatimaAppraisals() as $key => $appraisal) {
        $reset();

        foreach ($appraisal['beliefs'] as $belief => $value) {
            $post($characterPath.'/beliefs', ['name' => $belief, 'value' => $value]);
        }

        $post($characterPath.'/perceptions', [$appraisal['event']]);

        $emotions[$key] = [
            'request' => ['event' => $appraisal['event'], 'beliefs' => $appraisal['beliefs']],
            'body' => $get($characterPath.'/emotions')->json(),
        ];
    }

    // CiF gates the exchange on one rapport value, so every value the module can derive
    // (`(int) round(((trust + affinity) / 2) * rapport_scale)`, both in 0..1) is recorded and the
    // driver's real threshold is visible in the fixture instead of assumed by a test.
    $exchanges = [];

    foreach (range(0, 10) as $rapport) {
        $reset();
        $post($characterPath.'/beliefs', ['name' => 'RapportLevel(SELF, Player42)', 'value' => (string) $rapport]);

        $exchanges['rapport-' . $rapport] = [
            'request' => ['target' => 'Player42', 'rapport' => (string) $rapport],
            'body' => $post($characterPath.'/socialexchanges', ['target' => 'Player42'])->json(),
        ];
    }

    return [
        '_meta' => [
            'driver' => 'fatima',
            'image' => 'ogamex-ai-cognition-fatima:net8',
            'endpoint' => $base,
            'captured_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'capture_script' => 'scripts/capture-cognition-fixtures.php',
            'request_builder' => 'Modules\AI\Infrastructure\Cognition\FatimaClient + FatimaCognitionSession',
            'scenario' => $scenario,
            'instance' => $instance,
            'character' => $character,
            'note' => 'The recorded social-exchange target is the capture counterparty; the module reads only Name, Step and Volitions.',
        ],
        'channels' => [
            'scenario_created' => $channels['scenario_created'],
            'belief_updated' => $channels['belief_updated'],
            'perception_perceived' => $channels['perception_perceived'],
            'emotions' => $emotions,
            'socialexchanges' => $exchanges,
        ],
    ];
}

/** The event the affect engine sends for one appraisal branch and character. */
function fatimaEvent(string $branch): string
{
    return sprintf('Event(Action-End, Other, %s, %s)', $branch, AiArchetype::Miner->name);
}

/**
 * The signed OCC belief pairs the affect engine writes, one per appraisal it can drive.
 *
 * @return array<string, array{beliefs: array<string, string>, event: string}>
 */
function fatimaAppraisals(): array
{
    $beliefs = static fn (string $desirability, string $threat): array => [
        'StimulusDesirability(SELF, Other)' => $desirability,
        'StimulusThreat(SELF, Other)' => $threat,
    ];

    return [
        'aid' => ['beliefs' => $beliefs('0.5', '0'), 'event' => fatimaEvent('Aid')],
        'aid_strong' => ['beliefs' => $beliefs('0.9', '0'), 'event' => fatimaEvent('Aid')],
        'harm' => ['beliefs' => $beliefs('-0.4', '0'), 'event' => fatimaEvent('Harm')],
        'threat' => ['beliefs' => $beliefs('0', '-0.6'), 'event' => fatimaEvent('Threaten')],
        'neutral' => ['beliefs' => $beliefs('0', '0'), 'event' => fatimaEvent('Harm')],
    ];
}

/** @return array{_meta: array<string, mixed>, channels: array<string, mixed>} */
function captureCbrKit(): array
{
    $base = config('ai.cognition.experience.cbrkit.base_url');

    // The retriever is stateless and its score for a stored case depends only on that case's
    // features and the query, so the recordings below are a table of (query, case features) the
    // module really asks about, not a table of requests. The module's real shapes are here:
    // `EconomyUpgrades` asks with the planned building's object id alone, the conformance trial
    // extends that with the target level, and its differentiation probe asks with no planet.
    $recordings = [
        'canonical' => [
            'query' => ['object_id' => 2, 'target_level' => 6],
            'casebase' => [
                '9010' => ['planet_id' => 1, 'object_id' => 2, 'target_level' => 6],
                '9011' => ['planet_id' => 1, 'object_id' => 2, 'target_level' => 5],
                '9012' => ['planet_id' => 1, 'object_id' => 3, 'target_level' => 6],
                '9013' => ['planet_id' => 1, 'object_id' => 2, 'target_level' => 6],
                // A same-object case at a distant level. The native mean ranks it below a
                // different-object case at the right level, while the driver's categorical
                // identity ranks any same-object case above any other, so the two measures
                // disagree about the order and a hybrid merge is observable.
                '9014' => ['planet_id' => 1, 'object_id' => 2, 'target_level' => 2],
            ],
        ],
        'production' => [
            'query' => ['object_id' => 2],
            'casebase' => [
                '9020' => ['planet_id' => 1, 'object_id' => 2, 'target_level' => 5],
                '9021' => ['planet_id' => 1, 'object_id' => 3, 'target_level' => 5],
            ],
        ],
        'conformance_tie' => [
            'query' => ['object_id' => 2, 'target_level' => 6],
            'casebase' => [
                '9030' => ['planet_id' => 1, 'object_id' => 2, 'target_level' => 6],
                '9031' => ['planet_id' => 2, 'object_id' => 2, 'target_level' => 6],
                '9032' => ['planet_id' => 3, 'object_id' => 2, 'target_level' => 6],
                '9033' => ['planet_id' => 4, 'object_id' => 2, 'target_level' => 6],
            ],
        ],
        'conformance_differentiation' => [
            'query' => ['object_id' => 2, 'target_level' => 6],
            'casebase' => [
                '9040' => ['object_id' => 9, 'target_level' => 6],
                '9041' => ['object_id' => 3, 'target_level' => 6],
                '9042' => ['object_id' => 2, 'target_level' => 6],
            ],
        ],
    ];

    $channels = [];

    // The retriever validates the casebase before it scores anything, so the shape the
    // conformance trial's failure probe sends has a real refusal of its own.
    $refused = Http::baseUrl($base)->timeout(15)->acceptJson()->post('/retrieve', [
        'casebase' => 'not-an-object',
        'queries' => ['current' => ['object_id' => 1]],
    ]);

    foreach ($recordings as $name => $recording) {
        $response = Http::baseUrl($base)->timeout(15)->acceptJson()->post('/retrieve', [
            'casebase' => $recording['casebase'],
            'queries' => ['current' => $recording['query']],
        ]);

        $channels[$name] = [
            'request' => ['casebase' => $recording['casebase'], 'queries' => ['current' => $recording['query']]],
            'body' => $response->json(),
        ];
    }

    return [
        '_meta' => [
            'driver' => 'cbrkit',
            'image' => 'ogamex-ai-cognition-cbrkit:1.6.0',
            'endpoint' => $base,
            'captured_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'capture_script' => 'scripts/capture-cognition-fixtures.php',
            'request_builder' => 'Modules\AI\Infrastructure\Experience\CbrKitClient',
            'note' => 'The retriever weights planet_id and object_id as categorical identity and target_level as numeric, so a stored case scores independently of the other cases sent. That is why one score can be replayed for any request carrying the same case features under the same query.',
        ],
        'channels' => [
            'retrieve' => $channels,
            'refused_casebase' => [
                'request' => ['casebase' => 'not-an-object', 'queries' => ['current' => ['object_id' => 1]]],
                'status' => $refused->status(),
                'body' => $refused->json() ?? $refused->body(),
            ],
        ],
    ];
}

/** @return array{_meta: array<string, mixed>, channels: array<string, mixed>} */
function captureAgentOs(): array
{
    $base = config('ai.cognition.memory.agentos.base_url');

    // The same three alliance facts the recall tests record through the module's own recorder,
    // rendered the way `AgentOsLongTermMemory::describe()` renders them.
    $memories = [
        ['id' => 9020, 'text' => 'AllianceMembership {"alliance_tag":"RAVEN"}', 'tags' => ['AllianceMembership', 'Claimed']],
        ['id' => 9021, 'text' => 'AllianceMembership {"alliance_tag":"FORMER"}', 'tags' => ['AllianceMembership', 'Claimed']],
        ['id' => 9022, 'text' => 'AllianceMembership {"alliance_tag":"HIDDEN"}', 'tags' => ['AllianceMembership', 'Claimed']],
    ];

    $recall = static fn (string $query): Response => Http::baseUrl($base)->timeout(15)->acceptJson()->post('/recall', [
        'scopeId' => 'player-902000',
        'query' => $query,
        'limit' => count($memories),
        'memories' => $memories,
    ]);

    // `limit` is the whole candidate set: the module owns the caller's cut, so the driver's
    // complete ranking is what a test should replay. The driver answers a query with no lexical
    // overlap by returning the candidates in their given order, never by dropping any of them.
    //
    // Two queries are recorded because the ranking is what the query makes it: one ranks a fact
    // the native recency cut excludes, the other lifts a fact inside that cut above its recency
    // neighbour, and the hybrid merge has to treat the two differently.
    $ranked = [
        'alliance membership' => $recall('alliance membership')->json(),
        'former alliance tag' => $recall('former alliance tag')->json(),
    ];

    return [
        '_meta' => [
            'driver' => 'agentos',
            'image' => 'ogamex-ai-cognition-agentos:0.10.16',
            'endpoint' => $base,
            'captured_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'capture_script' => 'scripts/capture-cognition-fixtures.php',
            'request_builder' => 'Modules\AI\Infrastructure\Memory\AgentOsClient',
            'status' => 200,
            'note' => 'The ranking depends on the query and the memory texts, so a replay must match both. A query with no lexical overlap returns the candidates in their given order rather than an empty ranking.',
        ],
        'channels' => [
            'recall' => array_map(static fn (string $query, array $body): array => [
                'request' => ['query' => $query, 'limit' => count($memories), 'memories' => $memories],
                'body' => $body,
            ], array_keys($ranked), $ranked),
        ],
    ];
}
