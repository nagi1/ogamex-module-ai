<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\ClassifyInboundSocialExchangeAction;
use Modules\AI\Actions\RunAiConversationCycleAction;
use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Decision\BuildingScoringPolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\CasualPolicy;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TraderPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\ChatMessage;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

const CONVERSATION_NOW = '2026-09-11 12:00:00';

beforeEach(function (): void {
    $this->app->bind(AiClock::class, fn (): FixtureAiClock => $this->app->makeWith(FixtureAiClock::class, ['now' => CarbonImmutable::parse(CONVERSATION_NOW)]));
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    $this->app->bind(SocialCognition::class, NativeSocialCognition::class);
    $this->app->bind(RunAiSession::class, RunAiSessionAction::class);
    $this->app->bind(BuildingScoringPolicy::class, SeededBuildingScoringPolicy::class);
    $this->app->tag([MinerPolicy::class, TurtlePolicy::class, FleeterPolicy::class, TraderPolicy::class, CasualPolicy::class], ArchetypePolicy::class);
    $this->app->singleton(ArchetypePolicyResolver::class, fn ($app): ArchetypePolicyRegistry => $app->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $app->tagged(ArchetypePolicy::class),
    ]));
});

/**
 * Writes a direct message from another player to an enabled AI and returns its id.
 */
function inboundMessage(int $recipientId, int $senderId, string $text): int
{
    return (int) ChatMessage::create(['sender_id' => $senderId, 'recipient_id' => $recipientId, 'message' => $text])->id;
}

function enabledAiProfile(int $playerId, int $randomSeed = 42): AiProfile
{
    return AiProfile::create(['player_id' => $playerId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => $randomSeed, 'enabled' => true]);
}

/**
 * Writes the observation a committed message would produce, without depending on the
 * observer firing inside the test transaction.
 */
function observedChatMessage(int $playerId, int $subjectPlayerId, int $sourceId): AiObservation
{
    return AiObservation::create([
        'player_id' => $playerId,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => $sourceId,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $subjectPlayerId,
        'source_time' => CarbonImmutable::parse(CONVERSATION_NOW),
        'observed_at' => CarbonImmutable::parse(CONVERSATION_NOW),
    ]);
}

function runConversationCycle(int $playerId): int
{
    return app(RunAiConversationCycleAction::class)->handle($playerId, CarbonImmutable::parse(CONVERSATION_NOW));
}

function deliveredReplies(int $playerId): int
{
    return AiConversationReply::query()->where('player_id', $playerId)->where('state', AiConversationReplyState::Delivered)->count();
}

test('an inbound greeting is answered once through the authored delivery path', function (): void {
    $human = $this->createUser();
    enabledAiProfile($this->currentUserId);
    $sourceMessageId = inboundMessage($this->currentUserId, $human->id, 'hello');

    expect(runConversationCycle($this->currentUserId))->toBe(1);

    $exchange = AiSocialExchange::query()->where('player_id', $this->currentUserId)->sole();
    $reply = ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $human->id)->sole();

    expect($exchange->type)->toBe(AiSocialExchangeType::Greeting)
        ->and($exchange->state)->toBe(AiSocialExchangeState::Responded)
        ->and($exchange->response)->toBe(AiSocialResponse::Accept)
        ->and($reply->message)->toBeIn(['Greetings.', 'Hello.'])
        ->and($reply->reply_to_id)->toBe($sourceMessageId)
        ->and(deliveredReplies($this->currentUserId))->toBe(1)
        ->and((float) AiRelationship::query()->where('player_id', $this->currentUserId)->sole()->affinity)->toBeGreaterThan(0.0);
});

test('an unrecognised message stays unanswered and records nothing', function (): void {
    $human = $this->createUser();
    enabledAiProfile($this->currentUserId);
    inboundMessage($this->currentUserId, $human->id, 'what colour is your fleet?');

    expect(runConversationCycle($this->currentUserId))->toBe(0)
        ->and(AiSocialExchange::query()->count())->toBe(0)
        ->and(AiConversationReply::query()->count())->toBe(0)
        ->and(AiRelationship::query()->count())->toBe(0)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->count())->toBe(0);
});

test('an observation whose message is gone is left alone', function (): void {
    enabledAiProfile($this->currentUserId);
    observedChatMessage($this->currentUserId, $this->createUser()->id, 9_999_999);

    expect(runConversationCycle($this->currentUserId))->toBe(0)
        ->and(AiSocialExchange::query()->count())->toBe(0)
        ->and(AiConversationReply::query()->count())->toBe(0);
});

test('an observation addressed to the player itself produces no exchange', function (): void {
    $human = $this->createUser();
    enabledAiProfile($this->currentUserId);
    $messageId = inboundMessage($this->currentUserId, $human->id, 'hello');

    // An observation is identified by its player, source type and source id, so this row is the
    // one the cycle sees for that message. A self-addressed subject is refused by the exchange
    // recorder, and the message must stay open rather than look answered by a record that was
    // never written.
    observedChatMessage($this->currentUserId, $this->currentUserId, $messageId);

    expect(runConversationCycle($this->currentUserId))->toBe(0)
        ->and(AiSocialExchange::query()->count())->toBe(0)
        ->and(AiConversationReply::query()->count())->toBe(0);
});

test('a coercive warning is refused, remembered as a threat and never trusted', function (): void {
    $human = $this->createUser();
    enabledAiProfile($this->currentUserId);
    inboundMessage($this->currentUserId, $human->id, 'stop attacking me or else we will destroy you');

    expect(runConversationCycle($this->currentUserId))->toBe(1);

    $relationship = AiRelationship::query()->where('player_id', $this->currentUserId)->sole();

    expect(AiSocialExchange::query()->sole()->response)->toBe(AiSocialResponse::Reject)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->sole()->message)->toBe('I will not accept coercive terms.')
        ->and((float) $relationship->threat)->toBe(0.15)
        ->and((float) $relationship->trust)->toBe(0.0);
});

test('an apology that names no harm is answered with a request for the acknowledgement', function (): void {
    $human = $this->createUser();
    enabledAiProfile($this->currentUserId);
    inboundMessage($this->currentUserId, $human->id, 'sorry');

    expect(runConversationCycle($this->currentUserId))->toBe(1);

    expect(AiSocialExchange::query()->sole()->response)->toBe(AiSocialResponse::Clarify)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->sole()->message)
        ->toBeIn(['Please acknowledge the harm and explain how you will repair it.', 'I need a clearer acknowledgment before we discuss forgiveness.']);
});

test('a trade offer is declined because the module has no transport path', function (): void {
    $human = $this->createUser();
    enabledAiProfile($this->currentUserId);
    inboundMessage($this->currentUserId, $human->id, 'lets trade 2 metal for 3 crystal');

    expect(runConversationCycle($this->currentUserId))->toBe(1)
        ->and(AiSocialExchange::query()->sole()->response)->toBe(AiSocialResponse::Reject)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->sole()->message)
        ->toBe('I cannot authorize a trade without a validated transport path.');
});

test('an answered message is never reconsidered by a later cycle', function (): void {
    $human = $this->createUser();
    enabledAiProfile($this->currentUserId);
    inboundMessage($this->currentUserId, $human->id, 'hello');

    expect(runConversationCycle($this->currentUserId))->toBe(1)
        ->and(runConversationCycle($this->currentUserId))->toBe(0)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->count())->toBe(1);
});

test('the protocol stops after one response turn', function (): void {
    $human = $this->createUser();
    enabledAiProfile($this->currentUserId);
    inboundMessage($this->currentUserId, $human->id, 'hello');

    expect(runConversationCycle($this->currentUserId))->toBe(1);

    inboundMessage($this->currentUserId, $human->id, 'hello again');

    expect(runConversationCycle($this->currentUserId))->toBe(1);

    inboundMessage($this->currentUserId, $human->id, 'still here');

    expect(runConversationCycle($this->currentUserId))->toBe(0)
        ->and(AiSocialExchange::query()->count())->toBe(2)
        ->and(deliveredReplies($this->currentUserId))->toBe(2);
});

test('two automated neighbours stop greeting each other instead of looping', function (): void {
    $neighbour = $this->createUser();
    enabledAiProfile($this->currentUserId);
    enabledAiProfile($neighbour->id, 7);
    inboundMessage($this->currentUserId, $neighbour->id, 'hello neighbour');

    foreach (range(1, 6) as $ignored) {
        runConversationCycle($this->currentUserId);
        runConversationCycle($neighbour->id);
    }

    $total = ChatMessage::query()->whereIn('sender_id', [$this->currentUserId, $neighbour->id])->count();

    expect(deliveredReplies($this->currentUserId))->toBe(2)
        ->and(deliveredReplies($neighbour->id))->toBe(2)
        ->and($total)->toBe(5)
        ->and(runConversationCycle($this->currentUserId))->toBe(0)
        ->and(runConversationCycle($neighbour->id))->toBe(0)
        ->and(ChatMessage::query()->whereIn('sender_id', [$this->currentUserId, $neighbour->id])->count())->toBe(5);
});

test('a session answers a pending message before it decides anything else', function (): void {
    $human = $this->createUser();
    $profile = enabledAiProfile($this->currentUserId);
    inboundMessage($this->currentUserId, $human->id, 'hello there');
    $work = AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'conversation-session-' . $this->currentUserId,
        'state' => AiWorkState::Pending,
    ]);

    // The session entry point is what composes the cycle, so running it is the proof that a
    // real player answers a real message rather than only a test calling the cycle directly.
    app(RunAiSession::class)->handle($profile, $work);

    expect(deliveredReplies($this->currentUserId))->toBe(1)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->sole()->recipient_id)->toBe($human->id)
        ->and(AiWorkItem::query()->where('player_id', $this->currentUserId)->offset(1)->sole()->kind)->toBe(AiWorkKind::RunSession);
});

test('disabling the conversation switch leaves messages unanswered', function (): void {
    config(['ai.cognition.conversation.enabled' => false]);
    $human = $this->createUser();
    enabledAiProfile($this->currentUserId);
    inboundMessage($this->currentUserId, $human->id, 'hello');
    $profile = AiProfile::query()->where('player_id', $this->currentUserId)->sole();
    $work = AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'disabled-conversation-' . $this->currentUserId,
        'state' => AiWorkState::Pending,
    ]);

    app(RunAiSession::class)->handle($profile, $work);

    expect(AiSocialExchange::query()->count())->toBe(0)
        ->and(AiConversationReply::query()->count())->toBe(0)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->count())->toBe(0);
});

test('the classifier places a known exchange and ignores everything else', function (string $message, AiSocialExchangeType|null $type, array $terms): void {
    $classification = app(ClassifyInboundSocialExchangeAction::class)->handle($message);

    expect($classification?->type)->toBe($type)
        ->and($classification?->terms ?? [])->toBe($terms);
})->with([
    'greeting' => ['hello', AiSocialExchangeType::Greeting, []],
    'greeting with punctuation' => ['Hello.', AiSocialExchangeType::Greeting, []],
    'greeting in caps' => ['HEY', AiSocialExchangeType::Greeting, []],
    'thanks' => ['thanks!', AiSocialExchangeType::Thanks, []],
    'thank you' => ['thank you for the deut', AiSocialExchangeType::Thanks, []],
    'bare apology' => ['sorry', AiSocialExchangeType::Apology, []],
    'apology that names the harm' => ['sorry about your fleet', AiSocialExchangeType::Apology, [AiSocialTerm::AcknowledgesHarm->value => true]],
    'apology wins over the greeting it opens with' => ['hello, sorry about the hit', AiSocialExchangeType::Apology, [AiSocialTerm::AcknowledgesHarm->value => true]],
    'coercive warning' => ['stop attacking me or else we will destroy you', AiSocialExchangeType::Warning, [AiSocialTerm::Coercive->value => true]],
    'plain warning' => ['back off', AiSocialExchangeType::Warning, []],
    'ceasefire' => ['let us make a ceasefire', AiSocialExchangeType::CeasefireRequest, []],
    'truce' => ['can we agree a truce', AiSocialExchangeType::CeasefireRequest, []],
    'cooperation with the matched scope' => ['can we cooperate?', AiSocialExchangeType::CooperationRequest, [AiSocialTerm::Scope->value => 'cooperate']],
    'an alliance approach' => ['looking for an alliance with a discoverer class', AiSocialExchangeType::CooperationRequest, [AiSocialTerm::Scope->value => 'alliance']],
    'stated trade terms' => ['lets trade 2 metal for 3 crystal', AiSocialExchangeType::TradeOffer, [
        AiSocialTerm::OfferedResource->value => 'metal',
        AiSocialTerm::OfferedAmount->value => 2.0,
        AiSocialTerm::RequestedResource->value => 'crystal',
        AiSocialTerm::RequestedAmount->value => 3.0,
    ]],
    'incomplete trade offer' => ['want to trade?', AiSocialExchangeType::TradeOffer, []],
    'deuterium abbreviation is normalised' => ['trade 1kk deut for 2kk metal', AiSocialExchangeType::TradeOffer, [
        AiSocialTerm::OfferedResource->value => 'deuterium',
        AiSocialTerm::OfferedAmount->value => 1.0,
        AiSocialTerm::RequestedResource->value => 'metal',
        AiSocialTerm::RequestedAmount->value => 2.0,
    ]],
    'unrecognised question' => ['what colour is your fleet?', null, []],
    'empty message' => ['   ', null, []],
    'a greeting buried in a long message' => [str_repeat('hello ', 20), null, []],
]);
