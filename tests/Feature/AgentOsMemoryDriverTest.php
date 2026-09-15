<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\RecordAiMemoryFactAction;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
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
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

const AGENTOS_NOW = '2026-09-11 12:00:00 UTC';

beforeEach(function (): void {
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

function agentOsQuery(int $playerId, int $subjectPlayerId, int $limit = 5, string|null $queryText = 'anything'): MemoryRecallQuery
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

    Http::fake(['*' => Http::response(['ranking' => [['id' => $third->id], ['id' => $first->id]]], 200)]);

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(
        agentOsQuery($this->currentUserId, $subject->id, 2, 'alliance membership'),
    );

    // The driver's order decides which memory is surfaced first, and the module still owns the
    // record behind every id it accepts.
    expect(recalledTags($recalled))->toBe(['HIDDEN', 'RAVEN']);

    Http::assertSent(function ($request) use ($first, $second, $third): bool {
        $byId = [];

        foreach ($request['memories'] as $memory) {
            $byId[$memory['id']] = $memory;
        }

        return $request['scopeId'] === 'player-' . $this->currentUserId
            && $request['query'] === 'alliance membership'
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
    $fact = recordRecallFact($this->currentUserId, $subject->id, 'RAVEN', $observation->id);

    Http::fake(['*' => Http::response(['ranking' => [['id' => $fact->id]]], 200)]);

    app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id));

    Http::assertSent(fn ($request): bool => $request['memories'][0]['tags'] === ['AllianceMembership', 'Claimed', 'ChatMessage']);
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
    Http::fake(['*' => Http::response(['ranking' => [['id' => 999_999]]], 200)]);

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id));

    expect(recalledTags($recalled))->toBe(['RAVEN']);
});

test('an oversized driver answer is not read', function (): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');
    config(['ai.cognition.payload.maximum_response_bytes' => 32]);
    Http::fake(['*' => Http::response(['ranking' => [['id' => 1, 'evidence' => str_repeat('x', 200)]]], 200)]);

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id));

    expect(recalledTags($recalled))->toBe(['RAVEN']);
});

test('a driver answer that is not a usable ranking is never interpreted', function (mixed $body, int $status): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');
    Http::fake(['*' => Http::response($body, $status)]);

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id));

    expect(recalledTags($recalled))->toBe(['RAVEN']);
})->with([
    'not json' => ['not json', 200],
    'no ranking key' => [['considered' => 1], 200],
    'entry is not an object' => [['ranking' => [5]], 200],
    'id is not an integer' => [['ranking' => [['id' => '11']]], 200],
    'server error' => [['ranking' => [['id' => 1]]], 500],
]);

test('an empty ranking returns the module recall and does not charge the driver', function (): void {
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN');
    Http::fake(['*' => Http::response(['ranking' => []], 200)]);
    $query = agentOsQuery($this->currentUserId, $subject->id);

    expect(recalledTags(app(LongTermMemory::class)->recallRelevantMemories($query)))->toBe(['RAVEN']);

    // A recall engine that finds nothing relevant is answering, not failing, so the module
    // must keep asking it instead of tripping the circuit.
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
    Http::fake(['*' => Http::response(['ranking' => []], 500)]);
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
    $third = recordRecallFact($this->currentUserId, $subject->id, 'HIDDEN');

    Http::fake(['*' => Http::response(['ranking' => [['id' => $third->id]]], 200)]);

    // One memory is all the caller wants, but ranking a single memory would measure nothing, so
    // every authorised candidate is sent and only the answer is bounded.
    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id, 1));

    expect(recalledTags($recalled))->toBe(['HIDDEN']);
    Http::assertSent(fn ($request): bool => count($request['memories']) === 3 && $request['limit'] === 1);
});

test('the client refuses an empty query or an empty candidate set', function (): void {
    Http::fake();
    $client = agentOsClient();
    $memories = [['id' => 7, 'text' => 'AllianceMembership', 'tags' => []]];

    expect($client->recall(1, '', 5, $memories))->toBeNull()
        ->and($client->recall(1, 'anything', 5, []))->toBeNull();

    Http::assertNothingSent();
});

test('the client refuses an id it was never given', function (): void {
    Http::fake(['*' => Http::response(['ranking' => [['id' => 8]]], 200)]);

    expect(agentOsClient()->recall(1, 'anything', 5, [['id' => 7, 'text' => 'x', 'tags' => []]]))->toBeNull();
});

test('the client refuses a repeated id', function (): void {
    Http::fake(['*' => Http::response(['ranking' => [['id' => 7], ['id' => 7]]], 200)]);

    expect(agentOsClient()->recall(1, 'anything', 5, [['id' => 7, 'text' => 'x', 'tags' => []]]))->toBeNull();
});

test('the client accepts an empty ranking as an answer', function (): void {
    Http::fake(['*' => Http::response(['ranking' => []], 200)]);

    expect(agentOsClient()->recall(1, 'anything', 5, [['id' => 7, 'text' => 'x', 'tags' => []]]))->toBe([]);
});

test('hybrid memory does not evict the native recency cut', function (): void {
    config(['ai.cognition.mode' => 'hybrid']);
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN', null, '2026-09-11 11:59:00 UTC');
    recordRecallFact($this->currentUserId, $subject->id, 'FORMER', null, '2026-09-11 11:58:00 UTC');
    $oldest = recordRecallFact($this->currentUserId, $subject->id, 'HIDDEN', null, '2026-09-11 11:57:00 UTC');

    // The driver ranks a fact native recency cut out. Hybrid must not promote it over the cut,
    // because recency owns membership in hybrid mode.
    Http::fake(['*' => Http::response(['ranking' => [['id' => $oldest->id]]], 200)]);

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id, 2));

    expect(recalledTags($recalled))->toBe(['RAVEN', 'FORMER']);
});

test('hybrid memory floats a ranked fact within the native recency cut', function (): void {
    config(['ai.cognition.mode' => 'hybrid']);
    $subject = $this->createUser();
    recordRecallFact($this->currentUserId, $subject->id, 'RAVEN', null, '2026-09-11 11:59:00 UTC');
    $older = recordRecallFact($this->currentUserId, $subject->id, 'FORMER', null, '2026-09-11 11:58:00 UTC');
    recordRecallFact($this->currentUserId, $subject->id, 'HIDDEN', null, '2026-09-11 11:57:00 UTC');

    // The driver ranks a fact inside the cut. Relevance leads, recency membership is unchanged.
    Http::fake(['*' => Http::response(['ranking' => [['id' => $older->id]]], 200)]);

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(agentOsQuery($this->currentUserId, $subject->id, 2));

    expect(recalledTags($recalled))->toBe(['FORMER', 'RAVEN']);
});
