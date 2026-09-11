<?php

use Carbon\CarbonImmutable;
use Laravel\Ai\Prompts\AgentPrompt;
use Modules\AI\Actions\GenerateAiReplyAction;
use Modules\AI\Actions\RecordAiLanguageProposalAction;
use Modules\AI\Ai\Agents\OgameConversationReplyAgent;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\LanguageRequest;
use Modules\AI\Domain\Conversation\NativeContextBuilder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiLanguageProposalRejectionReason;
use Modules\AI\Enums\AiLanguageProposalState;
use Modules\AI\Enums\AiLanguageProposalType;
use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiLanguageResultStatus;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialResource;
use Modules\AI\Enums\AiUsageBudgetScope;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Infrastructure\Language\LaravelAiLanguageGateway;
use Modules\AI\Infrastructure\Language\NullLanguageGateway;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiLanguageProposal;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiMemoryFact;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiUsageBudget;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Providers\AIServiceProvider;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\ConcurrentLanguageRequestContextBuilder;
use Modules\AI\Tests\Support\FixtureAiClock;
use Modules\AI\Tests\Support\TimeoutOgameConversationReplyAgent;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';
require_once __DIR__ . '/../Support/FixedLanguageGateway.php';
require_once __DIR__ . '/../Support/LanguageGatewayTimeout.php';
require_once __DIR__ . '/../Support/TimeoutOgameConversationReplyAgent.php';
require_once __DIR__ . '/../Support/ConcurrentLanguageRequestContextBuilder.php';
require_once __DIR__ . '/../Support/LanguageTestFixtures.php';

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    config([
        'ai.language.enabled' => true,
        'ai.language.provider' => 'openai',
        'ai.language.model' => 'gpt-5-mini',
        'ai.language.timeout_seconds' => 17,
        'ai.language.context_characters' => 8_000,
        'ai.language.maximum_reply_characters' => 1_200,
        'ai.language.maximum_input_tokens' => 2_000,
        'ai.language.maximum_output_tokens' => 320,
        'ai.language.daily_limits' => [
            'universe' => ['attempts' => 500, 'input_tokens' => 1_000_000, 'output_tokens' => 160_000],
            'player' => ['attempts' => 10, 'input_tokens' => 20_000, 'output_tokens' => 3_200],
            'conversation' => ['attempts' => 3, 'input_tokens' => 6_000, 'output_tokens' => 960],
        ],
    ]);
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
    ]));
    app()->bind(ContextBuilder::class, NativeContextBuilder::class);
    app()->bind(LanguageGateway::class, LaravelAiLanguageGateway::class);
});

test('one Laravel AI structured prompt replaces the authored fallback and settles its receipt', function (): void {
    [$reply, $source] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 metal.');
    OgameConversationReplyAgent::fake([[
        'text' => 'I recorded your stated debt of 500 metal.',
        'interpretation' => 'claim',
        'candidates' => [[
            'type' => 'claim',
            'source_message_id' => $source->id,
            'resource' => 'metal',
            'amount' => 500,
            'due_at' => null,
        ]],
    ]])->preventStrayPrompts();

    $delivered = app(GenerateAiReplyAction::class)->handle($reply->id);
    $request = AiLanguageRequest::query()->sole();

    OgameConversationReplyAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === 'openai'
        && $prompt->model === 'gpt-5-mini'
        && $prompt->timeout === 17
        && $prompt->contains('I owe you 500 metal.')
        && $prompt->contains((string) $source->id));

    expect($delivered?->message)->toBe('I recorded your stated debt of 500 metal.')
        ->and($request->state)->toBe(AiLanguageRequestState::Completed)
        ->and($request->provider)->toBe('openai')
        ->and($request->model)->toBe('gpt-5-mini')
        ->and($request->context_hash)->toHaveLength(64)
        ->and(AiUsageReservation::query()->sole()->state)->toBe(AiUsageReservationState::Settled)
        ->and(AiLanguageProposal::query()->sole()->state)->toBe(AiLanguageProposalState::Accepted)
        ->and(AiMemoryFact::query()->where('player_id', $this->currentUserId)->where('predicate', AiMemoryPredicate::ResourceDebt)->sole()->value)->toMatchArray(['resource' => 'metal', 'amount' => 500]);
});

test('an SDK-valid proposal without explicit source terms is recorded as rejected', function (): void {
    [$reply, $source] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 metal.');
    OgameConversationReplyAgent::fake([[
        'text' => 'Please state the amount again.',
        'interpretation' => 'claim',
        'candidates' => [[
            'type' => 'claim',
            'source_message_id' => $source->id,
            'resource' => 'metal',
            'amount' => 900,
            'due_at' => null,
        ]],
    ]])->preventStrayPrompts();

    $delivered = app(GenerateAiReplyAction::class)->handle($reply->id);
    $proposal = AiLanguageProposal::query()->sole();

    expect($delivered?->message)->toBe('Please state the amount again.')
        ->and($proposal->state)->toBe(AiLanguageProposalState::Rejected)
        ->and($proposal->rejection_reason)->toBe(AiLanguageProposalRejectionReason::TermsNotExplicit)
        ->and(AiMemoryFact::query()->where('player_id', $this->currentUserId)->where('predicate', AiMemoryPredicate::ResourceDebt)->count())->toBe(0);
});

test('a definite provider failure settles its attempt once and delivers the authored fallback', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Can you answer this?');
    OgameConversationReplyAgent::fake([
        fn () => throw app()->makeWith(RuntimeException::class, ['message' => 'Provider unavailable.']),
    ])->preventStrayPrompts();

    $delivered = app(GenerateAiReplyAction::class)->handle($reply->id);
    $reservation = AiUsageReservation::query()->sole();

    expect($delivered?->message)->toBe('Authored fallback.')
        ->and(AiLanguageRequest::query()->sole()->state)->toBe(AiLanguageRequestState::Failed)
        ->and($reservation->state)->toBe(AiUsageReservationState::Settled)
        ->and($reservation->actual_input_tokens)->toBe(0)
        ->and(AiUsageBudget::query()->where('scope', AiUsageBudgetScope::Player->value)->where('scope_key', (string) $this->currentUserId)->sole()->reserved_attempts)->toBe(1);
});

test('a provider timeout keeps its attempt reserved for reconciliation and is never resent', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Can you answer this?');
    OgameConversationReplyAgent::fake([
        fn () => throw app()->makeWith(LanguageGatewayTimeout::class, ['message' => 'Provider timeout.']),
    ])->preventStrayPrompts();

    $delivered = app(GenerateAiReplyAction::class)->handle($reply->id);
    $reservation = AiUsageReservation::query()->sole();

    expect($delivered?->message)->toBe('Authored fallback.')
        ->and(AiLanguageRequest::query()->sole()->state)->toBe(AiLanguageRequestState::Uncertain)
        ->and($reservation->state)->toBe(AiUsageReservationState::Reserved)
        ->and(AiLanguageRequest::query()->sole()->provider_request_id)->toBeNull();
});

test('an uncertain request redelivers its authored fallback without another provider call', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Can you answer this?');
    languageRequestRecord($reply, AiLanguageRequestState::Uncertain);
    OgameConversationReplyAgent::fake()->preventStrayPrompts();

    $delivered = app(GenerateAiReplyAction::class)->handle($reply->id);

    expect($delivered?->message)->toBe('Authored fallback.')
        ->and(AiLanguageRequest::query()->count())->toBe(1)
        ->and(AiUsageReservation::query()->count())->toBe(1);

    OgameConversationReplyAgent::assertNeverPrompted();
});

test('provider-off and AI-to-AI replies keep the authored zero-prompt path', function (): void {
    [$disabledReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    config(['ai.language.enabled' => false]);
    OgameConversationReplyAgent::fake()->preventStrayPrompts();

    $disabledDelivery = app(GenerateAiReplyAction::class)->handle($disabledReply->id);

    config(['ai.language.enabled' => true]);
    [$aiReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Automated message.', true);
    $aiDelivery = app(GenerateAiReplyAction::class)->handle($aiReply->id);

    OgameConversationReplyAgent::assertNeverPrompted();

    expect($disabledDelivery?->message)->toBe('Authored fallback.')
        ->and($aiDelivery?->message)->toBe('Authored fallback.')
        ->and(AiLanguageRequest::query()->count())->toBe(0)
        ->and(AiUsageReservation::query()->count())->toBe(0);
});

test('the null gateway never loads provider configuration and the SDK gateway rejects malformed envelopes', function (): void {
    $request = languageRequest();
    $disabled = app(NullLanguageGateway::class)->generateConversationReply($request);
    OgameConversationReplyAgent::fake([
        'plain text without a structured response',
        ['text' => ' ', 'interpretation' => 'none', 'candidates' => []],
        ['text' => 'Valid text.', 'interpretation' => 'none', 'candidates' => [['type' => 'claim']]],
        ['text' => 'Valid text.', 'interpretation' => 'none', 'candidates' => [[], [], []]],
        ['text' => 'Valid text.', 'interpretation' => 'none', 'candidates' => ['invalid']],
        ['text' => 'Valid text.', 'interpretation' => 'none', 'candidates' => [[
            'type' => 'claim', 'source_message_id' => 1, 'resource' => 'metal', 'amount' => 1, 'due_at' => '2024-01-02T00:00:00+00:00',
        ]]],
        ['text' => 'Valid text.', 'interpretation' => 'none', 'candidates' => [[
            'type' => 'commitment', 'source_message_id' => 1, 'resource' => 'metal', 'amount' => 1, 'due_at' => 1,
        ]]],
        ['text' => 'Valid text.', 'interpretation' => 'none', 'candidates' => [[
            'type' => 'commitment', 'source_message_id' => 1, 'resource' => 'metal', 'amount' => 1, 'due_at' => null,
        ]]],
        ['text' => 'Valid text.', 'interpretation' => 'none', 'candidates' => [[
            'type' => 'commitment', 'source_message_id' => 1, 'resource' => 'metal', 'amount' => 1, 'due_at' => 'not-a-date',
        ]]],
        fn () => throw app()->makeWith(RuntimeException::class, ['message' => 'Transport failed.']),
    ])->preventStrayPrompts();
    $gateway = app(LaravelAiLanguageGateway::class);

    $textResponse = $gateway->generateConversationReply($request);
    $blankResponse = $gateway->generateConversationReply($request);
    $candidateResponse = $gateway->generateConversationReply($request);
    $tooManyCandidates = $gateway->generateConversationReply($request);
    $nonArrayCandidate = $gateway->generateConversationReply($request);
    $claimWithDueDate = $gateway->generateConversationReply($request);
    $commitmentWithInvalidDate = $gateway->generateConversationReply($request);
    $commitmentWithoutDueDate = $gateway->generateConversationReply($request);
    $commitmentWithMalformedDate = $gateway->generateConversationReply($request);
    $failedResponse = $gateway->generateConversationReply($request);
    app()->bind(OgameConversationReplyAgent::class, TimeoutOgameConversationReplyAgent::class);
    $timedOutResponse = $gateway->generateConversationReply($request);

    expect($disabled->status)->toBe(AiLanguageResultStatus::Disabled)
        ->and($textResponse->status)->toBe(AiLanguageResultStatus::Failed)
        ->and($blankResponse->status)->toBe(AiLanguageResultStatus::Invalid)
        ->and($candidateResponse->status)->toBe(AiLanguageResultStatus::Invalid)
        ->and($tooManyCandidates->status)->toBe(AiLanguageResultStatus::Invalid)
        ->and($nonArrayCandidate->status)->toBe(AiLanguageResultStatus::Invalid)
        ->and($claimWithDueDate->status)->toBe(AiLanguageResultStatus::Invalid)
        ->and($commitmentWithInvalidDate->status)->toBe(AiLanguageResultStatus::Invalid)
        ->and($commitmentWithoutDueDate->status)->toBe(AiLanguageResultStatus::Invalid)
        ->and($commitmentWithMalformedDate->status)->toBe(AiLanguageResultStatus::Invalid)
        ->and($failedResponse->status)->toBe(AiLanguageResultStatus::Failed)
        ->and($timedOutResponse->status)->toBe(AiLanguageResultStatus::TimedOut);
});

test('language admission falls back for missing state, protected-context overflow, exhausted capacity, and a running request', function (): void {
    expect(app(GenerateAiReplyAction::class)->handle(PHP_INT_MAX))->toBeNull();

    [$pendingReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    $pendingReply->update(['state' => AiConversationReplyState::Pending]);
    expect(app(GenerateAiReplyAction::class)->handle($pendingReply->id))->toBeNull();

    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    config(['ai.language.context_characters' => 1]);
    expect(app(GenerateAiReplyAction::class)->handle($reply->id)?->message)->toBe('Authored fallback.');

    [$budgetReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    config(['ai.language.context_characters' => 8_000]);
    config(['ai.language.daily_limits.conversation.attempts' => 0]);
    expect(app(GenerateAiReplyAction::class)->handle($budgetReply->id)?->message)->toBe('Authored fallback.');

    [$runningReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    languageRequestRecord($runningReply, AiLanguageRequestState::Generating);
    expect(app(GenerateAiReplyAction::class)->handle($runningReply->id))->toBeNull();

    [$missingSourceReply, $missingSource] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    $missingSource->delete();
    expect(app(GenerateAiReplyAction::class)->handle($missingSourceReply->id))->toBeNull();

    [$concurrentReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    app()->bind(ContextBuilder::class, fn (): ConcurrentLanguageRequestContextBuilder => app()->makeWith(ConcurrentLanguageRequestContextBuilder::class, [
        'duringBuild' => function () use ($concurrentReply): void {
            languageRequestRecord($concurrentReply, AiLanguageRequestState::Generating);
        },
    ]));
    expect(app(GenerateAiReplyAction::class)->handle($concurrentReply->id)?->message)->toBe('Authored fallback.');
});

test('the module provider resolves the configured language gateway', function (): void {
    app()->makeWith(AIServiceProvider::class, ['app' => $this->app])->register();
    config(['ai.language.enabled' => false]);
    expect(app(LanguageGateway::class))->toBeInstanceOf(NullLanguageGateway::class);

    config(['ai.language.enabled' => true]);
    expect(app(LanguageGateway::class))->toBeInstanceOf(LaravelAiLanguageGateway::class);
});

test('terminal requests, absent personas, unknown usage and invalid results preserve safe delivery behavior', function (): void {
    [$terminalReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    languageRequestRecord($terminalReply, AiLanguageRequestState::Completed);
    expect(app(GenerateAiReplyAction::class)->handle($terminalReply->id)?->message)->toBe('Authored fallback.');

    [$missingPersonaReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    AiProfile::query()->where('player_id', $this->currentUserId)->delete();
    expect(app(GenerateAiReplyAction::class)->handle($missingPersonaReply->id))->toBeNull();

    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7,
        'enabled' => true,
    ]);
    [$overCapReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    bindFixedLanguageResult(languageResult(AiLanguageResultStatus::Completed, 'Accepted text.', 2_001, 0));
    expect(app(GenerateAiReplyAction::class)->handle($overCapReply->id)?->message)->toBe('Authored fallback.')
        ->and(AiLanguageRequest::query()->where('conversation_reply_id', $overCapReply->id)->sole()->state)->toBe(AiLanguageRequestState::Uncertain);

    [$invalidReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    bindFixedLanguageResult(languageResult(AiLanguageResultStatus::Invalid, null, 0, 0));
    expect(app(GenerateAiReplyAction::class)->handle($invalidReply->id)?->message)->toBe('Authored fallback.')
        ->and(AiLanguageRequest::query()->where('conversation_reply_id', $invalidReply->id)->sole()->state)->toBe(AiLanguageRequestState::Invalid);

    [$completedWithoutProposals] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    bindFixedLanguageResult(languageResult(AiLanguageResultStatus::Completed, 'Accepted text.', 0, 0));
    expect(app(GenerateAiReplyAction::class)->handle($completedWithoutProposals->id)?->message)->toBe('Accepted text.')
        ->and(AiLanguageRequest::query()->where('conversation_reply_id', $completedWithoutProposals->id)->sole()->state)->toBe(AiLanguageRequestState::Completed);

    [$staleReply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Please answer.');
    bindFixedLanguageResult(languageResult(AiLanguageResultStatus::Completed, 'Ignored stale text.', 0, 0), function (LanguageRequest $request): void {
        AiLanguageRequest::query()->where('conversation_reply_id', $request->replyId)->update(['state' => AiLanguageRequestState::Completed]);
    });
    expect(app(GenerateAiReplyAction::class)->handle($staleReply->id)?->message)->toBe('Authored fallback.');
});

test('proposal persistence rejects unauthorized evidence and retains only explicit observed claims and commitments', function (): void {
    [$unauthorizedReply, $unauthorizedSource] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 metal.');
    $unauthorized = app(RecordAiLanguageProposalAction::class)->handle(
        languageRequestRecord($unauthorizedReply, AiLanguageRequestState::Generating),
        $unauthorizedReply,
        languageProposal(AiLanguageProposalType::Claim, $unauthorizedSource->id + 100, AiSocialResource::Metal, 500),
    );

    [$unobservedReply, $unobservedSource] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 metal.');
    AiObservation::query()->where('player_id', $this->currentUserId)->where('source_id', $unobservedSource->id)->delete();
    $unobserved = app(RecordAiLanguageProposalAction::class)->handle(
        languageRequestRecord($unobservedReply, AiLanguageRequestState::Generating),
        $unobservedReply,
        languageProposal(AiLanguageProposalType::Claim, $unobservedSource->id, AiSocialResource::Metal, 500),
    );

    [$differentResourceReply, $differentResourceSource] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 crystal.');
    $differentResource = app(RecordAiLanguageProposalAction::class)->handle(
        languageRequestRecord($differentResourceReply, AiLanguageRequestState::Generating),
        $differentResourceReply,
        languageProposal(AiLanguageProposalType::Claim, $differentResourceSource->id, AiSocialResource::Metal, 500),
    );

    [$missingMessageReply, $missingMessageSource] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 metal.');
    $missingMessageSource->delete();
    $missingMessage = app(RecordAiLanguageProposalAction::class)->handle(
        languageRequestRecord($missingMessageReply, AiLanguageRequestState::Generating),
        $missingMessageReply,
        languageProposal(AiLanguageProposalType::Claim, $missingMessageSource->id, AiSocialResource::Metal, 500),
    );

    [$missingTermsReply, $missingTermsSource] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you something.');
    $missingTerms = app(RecordAiLanguageProposalAction::class)->handle(
        languageRequestRecord($missingTermsReply, AiLanguageRequestState::Generating),
        $missingTermsReply,
        languageProposal(AiLanguageProposalType::Claim, $missingTermsSource->id, null, null),
    );

    $dueAt = CarbonImmutable::parse('2024-01-02T00:00:00+00:00');
    [$commitmentReply, $commitmentSource] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I will send 500 metal by ' . $dueAt->toIso8601String() . '.');
    $commitment = app(RecordAiLanguageProposalAction::class)->handle(
        languageRequestRecord($commitmentReply, AiLanguageRequestState::Generating),
        $commitmentReply,
        languageProposal(AiLanguageProposalType::Commitment, $commitmentSource->id, AiSocialResource::Metal, 500, $dueAt),
    );

    expect($unauthorized->rejection_reason)->toBe(AiLanguageProposalRejectionReason::SourceNotAuthorized)
        ->and($unobserved->rejection_reason)->toBe(AiLanguageProposalRejectionReason::SourceNotObserved)
        ->and($differentResource->rejection_reason)->toBe(AiLanguageProposalRejectionReason::TermsNotExplicit)
        ->and($missingMessage->rejection_reason)->toBe(AiLanguageProposalRejectionReason::SourceNotAuthorized)
        ->and($missingTerms->terms)->toBe([])
        ->and($commitment->state)->toBe(AiLanguageProposalState::Accepted)
        ->and(AiCommitment::query()->where('player_id', $this->currentUserId)->count())->toBe(1);
});
