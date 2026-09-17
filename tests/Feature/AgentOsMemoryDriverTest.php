<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\RecordAiMemoryFactAction;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
use Modules\AI\Domain\Conversation\NativeLongTermMemory;
use Modules\AI\Enums\AiCognitionMode;
use Modules\AI\Enums\AiMemoryDriver;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Infrastructure\Memory\AgentOsClient;
use Modules\AI\Infrastructure\Memory\AgentOsLongTermMemory;
use Modules\AI\Models\AiMemoryFact;
use Modules\AI\Models\AiObservation;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\LongTermMemorySelector;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\InteractsWithCognitionFixtures;
use Tests\IsolatedAccountTestCase;

require_once __DIR__.'/../Support/InteractsWithCognitionFixtures.php';

uses(IsolatedAccountTestCase::class, InteractsWithCognitionFixtures::class);

const AGENTOS_NOW = '2026-09-11 12:00:00 UTC';

/**
 * The recorded query the ranking and hybrid cases use.
 *
 * The driver's ranking is a snapshot: its tie-break between equally relevant memories is its own,
 * and a re-capture may return a different order for the same request. This query is recorded with
 * an order that disagrees with the module's own recency order, so a case using it can tell a
 * driver answer from a fallback. `'alliance membership'` is not that query.
 */
const DRIVER_RANKED_QUERY = 'former alliance tag';

/**
 * The ranked-recall paths replay what the running sidecar really answered, captured into
 * `tests/Fixtures/cognition/agentos.json`.
 *
 * The expected orders below are the committed recording's, not the module's recency order: the
 * driver ranks the text it is handed, and its answer is what the module has to honour. The
 * recordings show the driver's own ranking is shaped by the query, which is why the hybrid tests
 * use two different ones.
 */
beforeEach(function (): void {
    // External swap semantics: the driver's ranking decides which facts survive the caller limit.
    config(['ai.cognition.mode' => AiCognitionMode::External->value]);
    config(['ai.cognition.memory.driver' => AiMemoryDriver::AgentOs->value]);
    $this->app->bind(AiClock::class, SystemAiClock::class);
    // Routed through the selector so the module's real swapping wiring stays under test.
    app()->bind(LongTermMemory::class, fn (): LongTermMemory => app(LongTermMemorySelector::class)->resolve());
});

function recordRecallFact(int $playerId, int $subjectPlayerId, string $tag, int|null $sourceObservationId = null, string|null $validFrom = null): AiMemoryFact
{
    // Each fact needs its own source, because the recorder deduplicates on it and a repeated
    // source would silently return the first fact instead of creating another one.
    static $nextSourceId = 9000;

    return app(RecordAiMemoryFactAction::class)->handle(
        $playerId,
        $subjectPlayerId,
        AiMemoryPredicate::AllianceMembership,
        AiMemoryEvidenceKind::Claimed,
        ['alliance_tag' => $tag],
        $sourceObservationId ?? ++$nextSourceId,
        CarbonImmutable::parse($validFrom ?? AGENTOS_NOW),
    );
}

function agentOsQuery(int $playerId, int $subjectPlayerId, int $limit = 5, string|null $queryText = 'alliance membership'): MemoryRecallQuery
{
    return app()->makeWith(MemoryRecallQuery::class, [
        'playerId' => $playerId,
        'subjectPlayerId' => $subjectPlayerId,
        'now' => CarbonImmutable::parse(AGENTOS_NOW),
        'limit' => $limit,
        'queryText' => $queryText,
    ]);
}

/** @return list<string> the tag of every recalled fact, in the order it was returned */
function recalledTags(array $recalled): array
{
    return array_map(static fn (array $memory): string => (string) $memory['value']['alliance_tag'], $recalled);
}

/** The alliance tag the module renders into a memory's text, which is what the driver ranks. */
function recallTagOf(string $text): string
{
    return (string) json_decode(substr($text, strlen('AllianceMembership ')), true)['alliance_tag'];
}

/** What the module answers with no driver at all, which is the order a swap has to change. */
function nativeRecallTags(array $recalled): array
{
    return array_map(static fn (array $memory): string => (string) $memory['value']['alliance_tag'], $recalled);
}

/** The circuit breaker is driver-scoped, so the client is built the way the selector builds it. */
function agentOsClient(): AgentOsClient
{
    return app()->makeWith(AgentOsClient::class, [
        'circuit' => app()->makeWith(DriverCircuitBreaker::class, ['driver' => AiMemoryDriver::AgentOs->value]),
    ]);
}

test('the agentos memory driver resolves when it is configured', function (): void {
    expect(app(LongTermMemorySelector::class)->resolve())->toBeInstanceOf(AgentOsLongTermMemory::class);
});

test('the driver ranks the authorised candidate set and returns at most the caller limit', function (): void {
    $subject = $this->createUser();
    $first = recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');
    $second = recordRecallFact($this->currentUserId, $subject->id, 'FORMER');
    $third = recordRecallFact($this->currentUserId, $subject->id, 'HIDDEN');

    $this->fakeAgentOsDriver();

    // The order the committed recording ranks this suite's three facts in. It is read from the
    // recording rather than written here, because the driver's own tie-break may differ after a
    // re-capture and a literal would then assert a stale answer.
    $driverOrder = array_map(recallTagOf(...), $this->agentOsRankedTexts(DRIVER_RANKED_QUERY));

    $query = agentOsQuery($this->currentUserId, $subject->id, 2, DRIVER_RANKED_QUERY);
    $recalled = app(LongTermMemory::class)->recallRelevantMemories($query);
    $native = nativeRecallTags(app(NativeLongTermMemory::class)->recallRelevantMemories($query));

    // The answer is the recording's order, cut to the caller's limit. The native recall answers the
    // same candidate set in a different order, so this case tells a driver answer from a fallback.
    expect($driverOrder)->not->toBe($native)
        ->and(recalledTags($recalled))->toBe(array_slice($driverOrder, 0, 2))
        ->and(recalledTags($recalled))->not->toBe($native);

    Http::assertSent(function (ClientRequest $request) use ($first, $second, $third): bool {
        $byId = [];

        foreach ($request['memories'] as $memory) {
            $byId[$memory['id']] = $memory;
        }

        return $request['scopeId'] === 'player-' . $this->currentUserId
            && $request['query'] === DRIVER_RANKED_QUERY
            && $request['limit'] === 2
            && count($request['memories']) === 3
            && $byId[$second->id]['text'] === 'AllianceMembership {"alliance_tag":"FORMER"}'
            && $byId[$second->id]['tags'] === ['AllianceMembership', 'Claimed']
            && isset($byId[$first->id], $byId[$third->id]);
    });
});

test('a fact whose source observation is known carries the source type as a tag', function (): void {
    $subject = $this->createUser();
    $observation = AiObservation::create([
        'player_id' => $this->currentUserId,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 5001,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $subject->id,
        'source_time' => CarbonImmutable::parse(AGENTOS_NOW),
        'observed_at' => CarbonImmutable::parse(AGENTOS_NOW),
    ]);
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN', $observation->id);
    // The recorded ranking answers one candidate set, so the rest of that set is seeded too.
    recordRecallFact($this->currentUserId, $subject->id, 'FORMER');
    recordRecallFact($this->currentUserId, $subject->id, 'HIDDEN');

    $this->fakeAgentOsDriver();

    app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id));

    Http::assertSent(function (ClientRequest $request): bool {
        foreach ($request['memories'] as $memory) {
            if (str_contains($memory['text'], '"RAVEN"')) {
                return $memory['tags'] === ['AllianceMembership', 'Claimed', 'ChatMessage'];
            }
        }

        return false;
    });
});

test('an absent driver returns the module recall instead of a failure', function (): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');
    Http::fake(fn () => throw new ConnectionException('sidecar down'));

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id));

    expect(recalledTags($recalled))->toBe(['RAVEN']);
});

test('a driver answer naming an id the module never sent is refused', function (): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');

    $this->fakeAgentOsDriver($this->driverProbe('agentos.unsent_id'));

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id));

    expect(recalledTags($recalled))->toBe(['RAVEN']);
});

test('an oversized driver answer is not read', function (): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');
    config(['ai.cognition.payload.maximum_response_bytes' => 32]);

    $this->fakeAgentOsDriver($this->driverProbe('agentos.oversized'));

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id));

    expect(recalledTags($recalled))->toBe(['RAVEN']);
});

test('a driver answer that is not a usable ranking is never interpreted', function (string $probe): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');

    $this->fakeAgentOsDriver($this->driverProbe($probe));

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id));

    expect(recalledTags($recalled))->toBe(['RAVEN']);
})->with([
    'not json' => ['agentos.not_json'],
    'no ranking key' => ['agentos.no_ranking_key'],
    'entry is not an object' => ['agentos.entry_not_object'],
    'id is not an integer' => ['agentos.id_not_integer'],
    'server error' => ['agentos.server_error'],
]);

test('an empty ranking returns the module recall and does not charge the driver', function (): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');

    // The driver never answers a non-empty candidate set with an empty ranking; an engine that
    // finds nothing relevant is answering, not failing, and the module must keep asking it.
    $this->fakeAgentOsDriver($this->driverProbe('agentos.empty_ranking'));
    $query = agentOsQuery($this->currentUserId, $subject->id);

    expect(recalledTags(app(LongTermMemory::class)->recallRelevantMemories($query)))->toBe(['RAVEN']);

    app(LongTermMemory::class)->recallRelevantMemories($query);

    Http::assertSentCount(2);
});

test('a recall with no query text never contacts the driver', function (): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');
    Http::fake();

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(
        agentOsQuery($this->currentUserId, $subject->id, 5, null),
    );

    expect(recalledTags($recalled))->toBe(['RAVEN']);
    Http::assertNothingSent();
});

test('a recall with no candidate memory never contacts the driver', function (): void {
    $subject = $this->createUser();
    Http::fake();

    expect(app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id)))->toBe([]);

    Http::assertNothingSent();
});

test('a tripped circuit stops contacting a failing driver', function (): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');
    config(['ai.cognition.circuit.failures' => 3]);

    $this->fakeAgentOsDriver($this->driverProbe('agentos.server_error'));
    $query = agentOsQuery($this->currentUserId, $subject->id);

    foreach (range(1, 4) as $ignored) {
        app(LongTermMemory::class)->recallRelevantMemories($query);
    }

    Http::assertSentCount(3);
});

test('the driver only ever ranks a wider candidate set than the caller asked for', function (): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');
    recordRecallFact($this->currentUserId, $subject->id, 'FORMER');
    recordRecallFact($this->currentUserId, $subject->id, 'HIDDEN');

    $this->fakeAgentOsDriver();

    // One memory is all the caller wants, but ranking a single memory would measure nothing, so
    // every authorised candidate is sent and only the answer is bounded.
    $recalled = app(LongTermMemory::class)->recallRelevantMemories(
        agentOsQuery($this->currentUserId, $subject->id, 1, DRIVER_RANKED_QUERY),
    );

    expect(recalledTags($recalled))->toBe(array_slice(array_map(recallTagOf(...), $this->agentOsRankedTexts(DRIVER_RANKED_QUERY)), 0, 1));
    Http::assertSent(fn (ClientRequest $request): bool => count($request['memories']) === 3 && $request['limit'] === 1);
});

test('the client refuses an empty query or an empty candidate set', function (): void {
    Http::fake();

    expect(agentOsClient()->recall(1, '', 5, [['id' => 7, 'text' => 'AllianceMembership', 'tags' => []]]))->toBeNull()
        ->and(agentOsClient()->recall(1, 'anything', 5, []))->toBeNull();

    Http::assertNothingSent();
});

test('the client refuses an id it was never given', function (): void {
    Http::fake(['*' => $this->driverProbe('agentos.unsent_id')]);

    expect(agentOsClient()->recall(1, 'anything', 5, [['id' => 7, 'text' => 'x', 'tags' => []]]))->toBeNull();
});

test('the client refuses a repeated id', function (): void {
    // The repeated id has to be one the request carried, or the answer would be refused for
    // naming an unknown memory instead of for repeating one.
    Http::fake(['*' => $this->driverProbe('agentos.repeated_id', ['@candidate' => 7])]);

    expect(agentOsClient()->recall(1, 'anything', 5, [['id' => 7, 'text' => 'x', 'tags' => []]]))->toBeNull();
});

test('the client accepts an empty ranking as an answer', function (): void {
    Http::fake(['*' => $this->driverProbe('agentos.empty_ranking')]);

    expect(agentOsClient()->recall(1, 'anything', 5, [['id' => 7, 'text' => 'x', 'tags' => []]]))->toBe([]);
});

test('hybrid memory does not evict the native recency cut', function (): void {
    config(['ai.cognition.mode' => 'hybrid']);
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN', null, '2026-09-11 11:59:00 UTC');
    recordRecallFact($this->currentUserId, $subject->id, 'FORMER', null, '2026-09-11 11:58:00 UTC');
    recordRecallFact($this->currentUserId, $subject->id, 'HIDDEN', null, '2026-09-11 11:57:00 UTC');

    $this->fakeAgentOsDriver();

    // HIDDEN is the oldest fact, so the native cut of two excludes it, and this recording ranks it
    // above a fact the cut kept. Hybrid must not promote it: recency owns membership, relevance
    // only owns the order within it.
    $recalled = app(LongTermMemory::class)->recallRelevantMemories(
        agentOsQuery($this->currentUserId, $subject->id, 2, DRIVER_RANKED_QUERY),
    );

    $driverOrder = array_map(recallTagOf(...), $this->agentOsRankedTexts(DRIVER_RANKED_QUERY));

    expect($driverOrder)->toContain('HIDDEN')
        ->and(array_search('HIDDEN', $driverOrder, true))->toBeLessThan(array_search('RAVEN', $driverOrder, true))
        ->and(recalledTags($recalled))->not->toContain('HIDDEN')
        ->and(recalledTags($recalled))->toEqualCanonicalizing(['RAVEN', 'FORMER']);
});

test('hybrid memory floats a ranked fact within the native recency cut', function (): void {
    config(['ai.cognition.mode' => 'hybrid']);
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN', null, '2026-09-11 11:59:00 UTC');
    recordRecallFact($this->currentUserId, $subject->id, 'FORMER', null, '2026-09-11 11:58:00 UTC');
    recordRecallFact($this->currentUserId, $subject->id, 'HIDDEN', null, '2026-09-11 11:57:00 UTC');

    $this->fakeAgentOsDriver();

    // This query's recording ranks FORMER above the more recent RAVEN, so relevance leads inside a
    // cut recency decided: the two facts are the same pair and only their order changed.
    $recalled = app(LongTermMemory::class)->recallRelevantMemories(
        agentOsQuery($this->currentUserId, $subject->id, 2, DRIVER_RANKED_QUERY),
    );

    $driverOrder = array_map(recallTagOf(...), $this->agentOsRankedTexts(DRIVER_RANKED_QUERY));
    $cut = ['RAVEN', 'FORMER'];

    // The cut is the same pair either way, so only the order can show that relevance led: the
    // answer is the native cut read in the driver's order.
    expect($driverOrder)->toContain(...$cut)
        ->and(array_search('FORMER', $driverOrder, true))->toBeLessThan(array_search('RAVEN', $driverOrder, true))
        ->and(recalledTags($recalled))->toBe(array_values(array_filter(
            $driverOrder,
            fn (string $tag): bool => in_array($tag, $cut, true),
        )))
        ->and(recalledTags($recalled))->not->toBe($cut);
});
