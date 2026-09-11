<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\BuildAuthoredSocialReplyAction;
use Modules\AI\Actions\EvaluateAiSocialExchangeAction;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Actions\RecordAiSocialExchangeAction;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(SocialCognition::class, NativeSocialCognition::class);
    app()->bind(RandomSource::class, SeededRandomSource::class);
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

test('authored replies are bounded to known response variants and never invent terms', function (AiSocialResponse|null $response, array|null $terms): void {
    $exchange = new AiSocialExchange([
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
