<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\BuildAuthoredSocialReplyAction;
use Modules\AI\Actions\DeliverAiSealedReplyAction;
use Modules\AI\Actions\EvaluateAiSocialExchangeAction;
use Modules\AI\Actions\QueueAiSocialExchangeReplyAction;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Actions\RecordAiSocialExchangeAction;
use Modules\AI\Actions\SealAiAuthoredReplyAction;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCommitmentDirection;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\ChatMessage;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(SocialCognition::class, NativeSocialCognition::class);
    app()->bind(RandomSource::class, SeededRandomSource::class);
    app()->bind(AiClock::class, fn (): FixtureAiClock => new FixtureAiClock(CarbonImmutable::parse('2024-01-01 00:00:00 UTC')));
});

test('a trusted affordable help request becomes an accepted commitment and authored reply', function (): void {
    $counterparty = $this->createUser();
    $source = socialExchangeObservation($this->currentUserId, $counterparty->id, 601);
    app(RecordAiRelationshipInteractionAction::class)->handle(
        $this->currentUserId,
        $counterparty->id,
        $source->id,
        CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        0.8,
        0,
        0.1,
    );
    $exchange = app(RecordAiSocialExchangeAction::class)->handle(
        $this->currentUserId,
        $counterparty->id,
        $source->id,
        AiSocialExchangeType::HelpRequest,
        ['resource' => 'crystal', 'amount' => 100],
        CarbonImmutable::parse('2026-09-12 12:00:00 UTC'),
    );
    $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 100, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($evaluated->state)->toBe(AiSocialExchangeState::Responded)
        ->and($evaluated->response)->toBe(AiSocialResponse::Accept)
        ->and($evaluated->commitment_id)->not->toBeNull()
        ->and(AiCommitment::query()->findOrFail($evaluated->commitment_id)->state)->toBe(AiCommitmentState::Accepted)
        ->and(app(BuildAuthoredSocialReplyAction::class)->handle($evaluated, 42))->toBeIn([
            'I can help with the requested amount.',
            'Your request is accepted; I will provide the agreed amount.',
        ]);
});

test('a help exchange uses bounded native responses and cannot be evaluated twice', function (array $terms, float $availableAmount, AiSocialResponse $response, array|null $responseTerms): void {
    $counterparty = $this->createUser();
    $source = socialExchangeObservation($this->currentUserId, $counterparty->id, 610);
    $exchange = app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $counterparty->id, $source->id, AiSocialExchangeType::HelpRequest, $terms);
    $first = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, $availableAmount, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));
    $second = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 1_000, CarbonImmutable::parse('2026-09-11 14:00:00 UTC'));

    expect($first->response)->toBe($response)
        ->and($first->response_terms)->toBe($responseTerms)
        ->and($second->response)->toBe($response)
        ->and($second->revision)->toBe($first->revision)
        ->and(app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $this->currentUserId, 999, AiSocialExchangeType::HelpRequest, ['amount' => 1]))->toBeNull();
})->with('native social response cases');

test('an overdue exchange expires without creating a commitment', function (): void {
    $counterparty = $this->createUser();
    $source = socialExchangeObservation($this->currentUserId, $counterparty->id, 701);
    $exchange = app(RecordAiSocialExchangeAction::class)->handle(
        $this->currentUserId,
        $counterparty->id,
        $source->id,
        AiSocialExchangeType::HelpRequest,
        ['amount' => 10],
        CarbonImmutable::parse('2026-09-11 12:30:00 UTC'),
    );
    $expired = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 10, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));

    expect($expired->state)->toBe(AiSocialExchangeState::Expired)
        ->and($expired->commitment_id)->toBeNull();
});

test('an acknowledged low-threat apology is accepted without inventing a commitment', function (): void {
    $counterparty = $this->createUser();
    $exchange = app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $counterparty->id, 5004, AiSocialExchangeType::Apology, ['acknowledges_harm' => true]);
    AiRelationship::create(['player_id' => $this->currentUserId, 'other_player_id' => $counterparty->id, 'trust' => 0.3, 'affinity' => 0.3, 'threat' => 0.2, 'respect' => 0, 'social_importance' => 0, 'last_observation_id' => 5004, 'last_interaction_at' => CarbonImmutable::parse('2026-09-11 10:00 UTC'), 'revision' => 1]);

    $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 0, CarbonImmutable::parse('2026-09-11 11:00 UTC'));

    expect($evaluated?->response)->toBe(AiSocialResponse::Accept)
        ->and($evaluated?->response_reason)->toBe('apology_acknowledged')
        ->and($evaluated?->commitment_id)->toBeNull();
});

test('an unacknowledged or unsafe apology receives a bounded native response', function (array $terms, float $threat, AiSocialResponse $response): void {
    $counterparty = $this->createUser();
    $exchange = app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $counterparty->id, 5005 + (int) $threat, AiSocialExchangeType::Apology, $terms);
    AiRelationship::create(['player_id' => $this->currentUserId, 'other_player_id' => $counterparty->id, 'trust' => 0.8, 'affinity' => 0.8, 'threat' => $threat, 'respect' => 0, 'social_importance' => 0, 'last_observation_id' => $exchange->source_observation_id, 'last_interaction_at' => CarbonImmutable::parse('2026-09-11 10:00 UTC'), 'revision' => 1]);

    $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 0, CarbonImmutable::parse('2026-09-11 11:00 UTC'));

    expect($evaluated?->response)->toBe($response)
        ->and($evaluated?->commitment_id)->toBeNull();
})->with([
    'missing acknowledgement' => [[], 0.1, AiSocialResponse::Clarify],
    'unsafe history' => [['acknowledges_harm' => true], 0.8, AiSocialResponse::Reject],
]);

test('native social cognition keeps invalid, overcommitted, and uncertain requests distinct', function (): void {
    $evaluatedAt = CarbonImmutable::parse('2026-09-11 13:00:00 UTC');
    $engine = app(SocialCognition::class);

    $invalid = $engine->evaluateSocialExchange(new SocialExchangeContext(1, AiSocialExchangeType::HelpRequest, ['amount' => 0], 1, 1, 0, 0, 100, $evaluatedAt));
    $overcommitted = $engine->evaluateSocialExchange(new SocialExchangeContext(2, AiSocialExchangeType::HelpRequest, ['amount' => 10], 1, 1, 0, 3, 100, $evaluatedAt));
    $uncertain = $engine->evaluateSocialExchange(new SocialExchangeContext(3, AiSocialExchangeType::HelpRequest, ['amount' => 10], 0.5, 0.2, 0, 0, 100, $evaluatedAt));

    expect($invalid->response)->toBe(AiSocialResponse::Clarify)
        ->and($overcommitted->response)->toBe(AiSocialResponse::Reject)
        ->and($overcommitted->reason)->toBe('too_many_outstanding_commitments')
        ->and($uncertain->response)->toBe(AiSocialResponse::Clarify);
});

test('a safe but untrusted apology requests compensation without clearing prior harm', function (): void {
    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(new SocialExchangeContext(
        1,
        AiSocialExchangeType::Apology,
        ['acknowledges_harm' => true],
        0.1,
        0.1,
        0.1,
        0,
        0,
        CarbonImmutable::parse('2026-09-11 13:00:00 UTC'),
    ));

    expect($evaluation->response)->toBe(AiSocialResponse::Counter)
        ->and($evaluation->reason)->toBe('compensation_needed')
        ->and($evaluation->counterTerms)->toBe(['repair' => 'compensation']);
});

test('typed social protocols have bounded capability-safe native responses', function (AiSocialExchangeType $type, array $terms, CarbonImmutable|null $dueAt, float $threat, AiSocialResponse $response, string $reason): void {
    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(new SocialExchangeContext(
        1,
        $type,
        $terms,
        0.6,
        0.2,
        $threat,
        0,
        100,
        CarbonImmutable::parse('2026-09-11 13:00:00 UTC'),
        $dueAt,
    ));

    expect($evaluation->response)->toBe($response)
        ->and($evaluation->reason)->toBe($reason);
})->with('typed social protocol cases');

test('accepted compensation records an outstanding counterparty commitment with exact due terms', function (): void {
    $counterparty = $this->createUser();
    $dueAt = CarbonImmutable::parse('2026-09-12 12:00:00 UTC');
    $exchange = app(RecordAiSocialExchangeAction::class)->handle(
        $this->currentUserId,
        $counterparty->id,
        8001,
        AiSocialExchangeType::CompensationOffer,
        ['acknowledges_harm' => true, 'resource' => 'crystal', 'amount' => 200],
        $dueAt,
    );
    $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange?->id ?? 0, 0, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));
    $commitment = AiCommitment::query()->findOrFail($evaluated?->commitment_id);

    expect($evaluated?->response)->toBe(AiSocialResponse::Accept)
        ->and($commitment->state)->toBe(AiCommitmentState::Accepted)
        ->and($commitment->direction)->toBe(AiCommitmentDirection::ExpectedFromCounterparty)
        ->and($commitment->terms)->toEqual(['acknowledges_harm' => true, 'resource' => 'crystal', 'amount' => 200])
        ->and($commitment->due_at?->equalTo($dueAt))->toBeTrue()
        ->and($commitment->fulfilled_at)->toBeNull();
});

test('typed replies use the sealed authored delivery path exactly once', function (): void {
    $counterparty = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42, 'enabled' => true]);
    $sourceMessage = ChatMessage::create(['sender_id' => $counterparty->id, 'recipient_id' => $this->currentUserId, 'message' => 'Hello']);
    $source = socialExchangeObservation($this->currentUserId, $counterparty->id, $sourceMessage->id);
    $exchange = app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $counterparty->id, $source->id, AiSocialExchangeType::Greeting, []);
    $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange?->id ?? 0, 0, CarbonImmutable::parse('2024-01-01 00:30:00 UTC'));
    $reply = app(QueueAiSocialExchangeReplyAction::class)->handle($evaluated?->id ?? 0, CarbonImmutable::parse('2024-01-01 01:00:00 UTC'));
    $sealed = app(SealAiAuthoredReplyAction::class)->handle($reply?->id ?? 0);
    $firstDelivery = app(DeliverAiSealedReplyAction::class)->handle($sealed?->id ?? 0);
    $secondDelivery = app(DeliverAiSealedReplyAction::class)->handle($sealed?->id ?? 0);

    expect($reply)->not->toBeNull()
        ->and($sealed?->state)->toBe(AiConversationReplyState::Sealed)
        ->and($firstDelivery?->id)->toBe($secondDelivery?->id)
        ->and($firstDelivery?->reply_to_id)->toBe($sourceMessage->id)
        ->and(AiConversationReply::query()->findOrFail($reply?->id ?? 0)->state)->toBe(AiConversationReplyState::Delivered)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $counterparty->id)->count())->toBe(1);
});

test('the typed reply queue rejects unknown exchanges and incomplete social context', function (): void {
    $counterparty = $this->createUser();
    $exchange = AiSocialExchange::create([
        'player_id' => $this->currentUserId,
        'counterparty_player_id' => $counterparty->id,
        'source_observation_id' => 9100,
        'type' => AiSocialExchangeType::Greeting,
        'terms' => [],
        'state' => AiSocialExchangeState::Responded,
        'response' => AiSocialResponse::Accept,
        'revision' => 1,
    ]);
    $expiresAt = CarbonImmutable::parse('2024-01-01 01:00:00 UTC');

    expect(app(QueueAiSocialExchangeReplyAction::class)->handle(PHP_INT_MAX, $expiresAt))->toBeNull()
        ->and(app(QueueAiSocialExchangeReplyAction::class)->handle($exchange->id, $expiresAt))->toBeNull();

    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42, 'enabled' => true]);

    expect(app(QueueAiSocialExchangeReplyAction::class)->handle($exchange->id, $expiresAt))->toBeNull();
});

test('social protocol depth is limited to one response turn', function (): void {
    $counterparty = $this->createUser();

    expect(app(EvaluateAiSocialExchangeAction::class)->handle(PHP_INT_MAX, 0, CarbonImmutable::parse('2024-01-01 00:30:00 UTC')))->toBeNull()
        ->and(app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $counterparty->id, 9001, AiSocialExchangeType::Greeting, [], null, 0))->toBeNull()
        ->and(app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $counterparty->id, 9002, AiSocialExchangeType::Greeting, [], null, 3))->toBeNull()
        ->and(app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $counterparty->id, 9003, AiSocialExchangeType::Greeting, [], null, 2)?->protocol_depth)->toBe(2);
});

test('authored replies are bounded to known response variants and never invent terms', function (AiSocialResponse|null $response, array|null $terms): void {
    $exchange = new AiSocialExchange([
        'type' => AiSocialExchangeType::HelpRequest,
        'response' => $response,
        'response_terms' => $terms,
        'revision' => 1,
    ]);
    $exchange->id = 1;

    $reply = app(BuildAuthoredSocialReplyAction::class)->handle($exchange, 42);

    if ($response === null) {
        expect($reply)->toBeNull();

        return;
    }

    expect($reply)->toBeString()->not->toBeEmpty();

    if ($response === AiSocialResponse::Counter) {
        expect($reply)->toContain('20');
    }
})->with('authored social response cases');

test('authored protocol replies remain within their typed response families', function (AiSocialExchangeType $type, AiSocialResponse $response): void {
    $exchange = new AiSocialExchange([
        'type' => $type,
        'response' => $response,
        'response_terms' => ['amount' => 20],
        'revision' => 1,
    ]);
    $exchange->id = 2;

    expect(app(BuildAuthoredSocialReplyAction::class)->handle($exchange, 42))->toBeString()->not->toBeEmpty();
})->with('authored protocol reply cases');

dataset('native social response cases', [
    'insufficient availability counters' => [['amount' => 100], 20.0, AiSocialResponse::Counter, ['amount' => 20]],
    'untrusted request rejects' => [['amount' => 10], 100.0, AiSocialResponse::Reject, null],
    'missing amount clarifies' => [['resource' => 'metal'], 100.0, AiSocialResponse::Clarify, null],
]);

dataset('authored social response cases', [
    'no response remains silent' => [null, null],
    'accept is authored' => [AiSocialResponse::Accept, null],
    'reject is authored' => [AiSocialResponse::Reject, null],
    'counter uses only its supplied amount' => [AiSocialResponse::Counter, ['amount' => 20]],
    'clarification is authored' => [AiSocialResponse::Clarify, null],
]);

dataset('typed social protocol cases', [
    'greeting' => [AiSocialExchangeType::Greeting, [], null, 0, AiSocialResponse::Accept, 'routine_acknowledgement'],
    'thanks' => [AiSocialExchangeType::Thanks, [], null, 0, AiSocialResponse::Accept, 'routine_acknowledgement'],
    'invalid trade terms clarify' => [AiSocialExchangeType::TradeOffer, [], null, 0, AiSocialResponse::Clarify, 'missing_or_invalid_trade_terms'],
    'valid trade rejects without transport capability' => [AiSocialExchangeType::TradeOffer, ['offered_resource' => 'metal', 'offered_amount' => 100, 'requested_resource' => 'crystal', 'requested_amount' => 50], null, 0, AiSocialResponse::Reject, 'transport_capability_unavailable'],
    'ceasefire needs expiry' => [AiSocialExchangeType::CeasefireRequest, [], null, 0, AiSocialResponse::Clarify, 'missing_ceasefire_expiry'],
    'ceasefire does not claim enforcement' => [AiSocialExchangeType::CeasefireRequest, [], CarbonImmutable::parse('2026-09-12 13:00:00 UTC'), 0, AiSocialResponse::Clarify, 'ceasefire_enforcement_unavailable'],
    'unsafe ceasefire rejects' => [AiSocialExchangeType::CeasefireRequest, [], CarbonImmutable::parse('2026-09-12 13:00:00 UTC'), 0.8, AiSocialResponse::Reject, 'unsafe_ceasefire_request'],
    'coercive warning rejects' => [AiSocialExchangeType::Warning, ['coercive' => true], null, 0, AiSocialResponse::Reject, 'coercive_warning'],
    'ordinary warning does not create a promise' => [AiSocialExchangeType::Warning, [], null, 0, AiSocialResponse::Clarify, 'warning_acknowledged_without_commitment'],
    'cooperation needs a scope' => [AiSocialExchangeType::CooperationRequest, [], null, 0, AiSocialResponse::Clarify, 'missing_cooperation_scope'],
    'cooperation does not invent a game action' => [AiSocialExchangeType::CooperationRequest, ['scope' => 'mutual defence'], null, 0, AiSocialResponse::Clarify, 'cooperation_capability_unavailable'],
    'unsafe cooperation rejects' => [AiSocialExchangeType::CooperationRequest, ['scope' => 'mutual defence'], null, 0.8, AiSocialResponse::Reject, 'insufficient_trust'],
    'compensation needs acknowledgement' => [AiSocialExchangeType::CompensationOffer, [], CarbonImmutable::parse('2026-09-12 13:00:00 UTC'), 0, AiSocialResponse::Clarify, 'harm_not_acknowledged'],
    'compensation needs a due time' => [AiSocialExchangeType::CompensationOffer, ['acknowledges_harm' => true, 'resource' => 'metal', 'amount' => 100], null, 0, AiSocialResponse::Clarify, 'missing_compensation_due_at'],
    'compensation needs valid terms' => [AiSocialExchangeType::CompensationOffer, ['acknowledges_harm' => true, 'resource' => 'unknown', 'amount' => 100], CarbonImmutable::parse('2026-09-12 13:00:00 UTC'), 0, AiSocialResponse::Clarify, 'missing_or_invalid_compensation_terms'],
    'compensation rejects nonnumeric amount' => [AiSocialExchangeType::CompensationOffer, ['acknowledges_harm' => true, 'resource' => 'metal', 'amount' => '100'], CarbonImmutable::parse('2026-09-12 13:00:00 UTC'), 0, AiSocialResponse::Clarify, 'missing_or_invalid_compensation_terms'],
    'unsafe compensation rejects' => [AiSocialExchangeType::CompensationOffer, ['acknowledges_harm' => true, 'resource' => 'metal', 'amount' => 100], CarbonImmutable::parse('2026-09-12 13:00:00 UTC'), 0.8, AiSocialResponse::Reject, 'harm_not_repaired'],
]);

dataset('authored protocol reply cases', [
    'apology acceptance' => [AiSocialExchangeType::Apology, AiSocialResponse::Accept],
    'apology rejection' => [AiSocialExchangeType::Apology, AiSocialResponse::Reject],
    'apology clarification' => [AiSocialExchangeType::Apology, AiSocialResponse::Clarify],
    'apology counter' => [AiSocialExchangeType::Apology, AiSocialResponse::Counter],
    'greeting' => [AiSocialExchangeType::Greeting, AiSocialResponse::Accept],
    'thanks' => [AiSocialExchangeType::Thanks, AiSocialResponse::Accept],
    'trade clarification' => [AiSocialExchangeType::TradeOffer, AiSocialResponse::Clarify],
    'trade rejection' => [AiSocialExchangeType::TradeOffer, AiSocialResponse::Reject],
    'trade fallback' => [AiSocialExchangeType::TradeOffer, AiSocialResponse::Accept],
    'ceasefire rejection' => [AiSocialExchangeType::CeasefireRequest, AiSocialResponse::Reject],
    'ceasefire fallback' => [AiSocialExchangeType::CeasefireRequest, AiSocialResponse::Clarify],
    'warning rejection' => [AiSocialExchangeType::Warning, AiSocialResponse::Reject],
    'warning fallback' => [AiSocialExchangeType::Warning, AiSocialResponse::Clarify],
    'cooperation rejection' => [AiSocialExchangeType::CooperationRequest, AiSocialResponse::Reject],
    'cooperation clarification' => [AiSocialExchangeType::CooperationRequest, AiSocialResponse::Clarify],
    'cooperation fallback' => [AiSocialExchangeType::CooperationRequest, AiSocialResponse::Accept],
    'compensation acceptance' => [AiSocialExchangeType::CompensationOffer, AiSocialResponse::Accept],
    'compensation rejection' => [AiSocialExchangeType::CompensationOffer, AiSocialResponse::Reject],
    'compensation clarification' => [AiSocialExchangeType::CompensationOffer, AiSocialResponse::Clarify],
    'compensation counter' => [AiSocialExchangeType::CompensationOffer, AiSocialResponse::Counter],
]);

function socialExchangeObservation(int $playerId, int $counterpartyId, int $sourceId): AiObservation
{
    return AiObservation::create([
        'player_id' => $playerId,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => $sourceId,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $counterpartyId,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);
}
