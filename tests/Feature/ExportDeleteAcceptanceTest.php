<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\RecordAiMemoryFactAction;
use Modules\AI\Actions\RunAiConversationCycleAction;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiMemoryDriver;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiMemoryFact;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Observers\RedactDeletedChatMemory;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\LongTermMemorySelector;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\InteractsWithCognitionFixtures;
use OGame\Models\ChatMessage;
use Tests\IsolatedAccountTestCase;

require_once __DIR__.'/../Support/InteractsWithCognitionFixtures.php';

uses(IsolatedAccountTestCase::class, InteractsWithCognitionFixtures::class);

const DELETE_ACCEPTANCE_NOW = '2026-09-11 12:00:00 UTC';

/**
 * 3J acceptance: what a deletion has to take with it, and what a driver swap must not touch.
 *
 * The module's own tables are the canonical state, so this suite treats them as the export:
 * it proves the state is complete enough to survive a driver change, and that deleting a source
 * removes what that source produced everywhere it could be read from.
 */
beforeEach(function (): void {
    $this->app->bind(AiClock::class, SystemAiClock::class);
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    $this->app->bind(SocialCognition::class, NativeSocialCognition::class);
    app()->bind(LongTermMemory::class, fn (): LongTermMemory => app(LongTermMemorySelector::class)->resolve());
});

/** The canonical module state, which is what an export would carry. */
function canonicalState(int $playerId): array
{
    return [
        'profiles' => AiProfile::query()->where('player_id', $playerId)->orderBy('id')->get()->toArray(),
        'relationships' => AiRelationship::query()->where('player_id', $playerId)->orderBy('id')->get()->toArray(),
        'facts' => AiMemoryFact::query()->where('player_id', $playerId)->orderBy('id')->get()->toArray(),
        'commitments' => AiCommitment::query()->where('player_id', $playerId)->orderBy('id')->get()->toArray(),
        'exchanges' => AiSocialExchange::query()->where('player_id', $playerId)->orderBy('id')->get()->toArray(),
        'replies' => AiConversationReply::query()->where('player_id', $playerId)->orderBy('id')->get()->toArray(),
    ];
}

function recallQueryFor(int $playerId, int $subjectPlayerId, string|null $queryText = null): MemoryRecallQuery
{
    return app()->makeWith(MemoryRecallQuery::class, [
        'playerId' => $playerId,
        'subjectPlayerId' => $subjectPlayerId,
        'now' => CarbonImmutable::parse(DELETE_ACCEPTANCE_NOW),
        'limit' => 5,
        'queryText' => $queryText,
    ]);
}

/** Records one fact about a counterparty from a real committed message. */
function deletedSourceFixture(int $playerId, int $subjectPlayerId, string $message): array
{
    $chatMessage = ChatMessage::create(['sender_id' => $subjectPlayerId, 'recipient_id' => $playerId, 'message' => $message]);
    // An enabled module's committed-message observer records this row itself, so the fixture
    // converges on the unique source identity rather than inserting a second one.
    $observation = AiObservation::query()->firstOrCreate([
        'player_id' => $playerId,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => $chatMessage->id,
    ], [
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $subjectPlayerId,
        'source_time' => CarbonImmutable::parse(DELETE_ACCEPTANCE_NOW),
        'observed_at' => CarbonImmutable::parse(DELETE_ACCEPTANCE_NOW),
    ]);
    $fact = app(RecordAiMemoryFactAction::class)->handle(
        $playerId,
        $subjectPlayerId,
        AiMemoryPredicate::AllianceMembership,
        AiMemoryEvidenceKind::Claimed,
        ['alliance_tag' => 'RAVEN'],
        $observation->id,
        CarbonImmutable::parse(DELETE_ACCEPTANCE_NOW),
    );

    return [$chatMessage, $observation, $fact];
}

test('deleting a chat message takes the facts it produced out of recall under either driver', function (): void {
    $human = $this->createUser();
    [$chatMessage, , $fact] = deletedSourceFixture($this->currentUserId, $human->id, 'hello');

    expect(app(LongTermMemory::class)->recallRelevantMemories(recallQueryFor($this->currentUserId, $human->id)))
        ->toHaveCount(1);

    // The observer the module registers in production is what carries a deletion into cognition.
    ChatMessage::observe(RedactDeletedChatMemory::class);
    $chatMessage->delete();

    expect($fact->fresh()?->redacted_at)->not->toBeNull()
        ->and(app(LongTermMemory::class)->recallRelevantMemories(recallQueryFor($this->currentUserId, $human->id)))
        ->toBe([]);

    // And under the driver, which is the case that would otherwise need a provider-side delete.
    config(['ai.cognition.memory.driver' => AiMemoryDriver::AgentOs->value]);
    Http::fake();

    expect(app(LongTermMemory::class)->recallRelevantMemories(recallQueryFor($this->currentUserId, $human->id, 'hello')))
        ->toBe([]);
    Http::assertNothingSent();
});

test('a deleted message is never answered and leaves nothing to deliver', function (): void {
    $human = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42, 'enabled' => true]);
    [$chatMessage] = deletedSourceFixture($this->currentUserId, $human->id, 'hello');

    $cycle = app(RunAiConversationCycleAction::class);
    expect($cycle->handle($this->currentUserId, CarbonImmutable::parse(DELETE_ACCEPTANCE_NOW)))->toBe(1);

    // A second conversation whose source disappears before the account answers it.
    $second = ChatMessage::create(['sender_id' => $human->id, 'recipient_id' => $this->currentUserId, 'message' => 'hello again']);
    $second->delete();

    expect($cycle->handle($this->currentUserId, CarbonImmutable::parse(DELETE_ACCEPTANCE_NOW)))->toBe(0)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->count())->toBe(1)
        ->and(AiSocialExchange::query()->where('player_id', $this->currentUserId)->count())->toBe(1);

    unset($chatMessage);
});

test('swapping the recall driver changes the order and never the canonical state', function (): void {
    $human = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42, 'enabled' => true]);
    [, $observation] = deletedSourceFixture($this->currentUserId, $human->id, 'hello');
    app(RecordAiMemoryFactAction::class)->handle(
        $this->currentUserId,
        $human->id,
        AiMemoryPredicate::ResourceDebt,
        AiMemoryEvidenceKind::Claimed,
        ['amount' => 200],
        $observation->id + 1,
        CarbonImmutable::parse('2026-09-11 11:00:00 UTC'),
    );

    config(['ai.cognition.memory.driver' => AiMemoryDriver::Native->value]);
    $before = canonicalState($this->currentUserId);
    $nativeOrder = app(LongTermMemory::class)->recallRelevantMemories(recallQueryFor($this->currentUserId, $human->id, 'debt'));

    // The driven order is the driver's; only the envelope is the recorded one, carrying this
    // account's own ids.
    $this->fakeAgentOsDriver($this->driverProbe('agentos.reversed_pair', [
        '@first' => (int) $nativeOrder[0]['id'],
        '@second' => (int) $nativeOrder[1]['id'],
    ]));
    config(['ai.cognition.memory.driver' => AiMemoryDriver::AgentOs->value]);
    $drivenOrder = app(LongTermMemory::class)->recallRelevantMemories(recallQueryFor($this->currentUserId, $human->id, 'debt'));

    // The driver reorders the same evidence, and the export is untouched by which one answered.
    expect(array_column($drivenOrder, 'id'))->toBe(array_reverse(array_column($nativeOrder, 'id')))
        ->and(canonicalState($this->currentUserId))->toBe($before);
});

test('a driver that is absent changes nothing about the canonical state either', function (): void {
    $human = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42, 'enabled' => true]);
    deletedSourceFixture($this->currentUserId, $human->id, 'hello');

    config(['ai.cognition.memory.driver' => AiMemoryDriver::AgentOs->value]);
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('sidecar down'));

    $before = canonicalState($this->currentUserId);
    $recalled = app(LongTermMemory::class)->recallRelevantMemories(recallQueryFor($this->currentUserId, $human->id, 'hello'));

    expect($recalled)->toHaveCount(1)
        ->and(canonicalState($this->currentUserId))->toBe($before)
        ->and(app()->makeWith(DriverCircuitBreaker::class, ['driver' => AiMemoryDriver::AgentOs->value])->allowsRequest())->toBeTrue();
});
