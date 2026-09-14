<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Actions\GenerateAiReplyAction;
use Modules\AI\Actions\RunAiConversationCycleAction;
use Modules\AI\Domain\Conversation\ConversationRoutePolicy;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiConversationRoute;
use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiLanguageResultStatus;
use Modules\AI\Enums\AiQueueName;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Jobs\GenerateAiReply;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\ChatMessage;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/FixtureAiClock.php';
require_once __DIR__ . '/../Support/FixedLanguageGateway.php';
require_once __DIR__ . '/../Support/LanguageTestFixtures.php';

// The module is enabled so its own bindings and configuration are the ones under test.
uses(AiQueueModuleTestCase::class);

// The module clock is pinned to the time the host test case travels to, so a fixture's
// real-now expiry stays ahead of it.
const ESCALATION_NOW = '2024-01-01 00:00:00';

dataset('routine exchange types', [AiSocialExchangeType::Greeting, AiSocialExchangeType::Thanks]);

dataset('substantive exchange types', [
    AiSocialExchangeType::HelpRequest,
    AiSocialExchangeType::Apology,
    AiSocialExchangeType::TradeOffer,
    AiSocialExchangeType::CeasefireRequest,
    AiSocialExchangeType::Warning,
    AiSocialExchangeType::CooperationRequest,
    AiSocialExchangeType::CompensationOffer,
]);

beforeEach(function (): void {
    config([
        'ai.language.enabled' => false,
        'ai.language.provider' => 'openai',
        'ai.language.model' => 'gpt-5-mini',
        'ai.language.timeout_seconds' => 20,
        'ai.language.context_characters' => 8_000,
        'ai.language.maximum_reply_characters' => 1_200,
        'ai.language.maximum_input_tokens' => 2_000,
        'ai.language.maximum_output_tokens' => 320,
        'ai.language.universe_scope' => 'default',
        'ai.language.daily_limits' => [
            'universe' => ['attempts' => 500, 'input_tokens' => 1_000_000, 'output_tokens' => 160_000],
            'player' => ['attempts' => 10, 'input_tokens' => 20_000, 'output_tokens' => 3_200],
            'conversation' => ['attempts' => 3, 'input_tokens' => 6_000, 'output_tokens' => 960],
        ],
    ]);
    $this->app->bind(AiClock::class, fn (): FixtureAiClock => $this->app->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse(ESCALATION_NOW),
    ]));
});

function escalationProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function escalationInboundMessage(int $recipientId, int $senderId, string $text): ChatMessage
{
    return ChatMessage::create(['sender_id' => $senderId, 'recipient_id' => $recipientId, 'message' => $text]);
}

function runEscalationCycle(int $playerId): int
{
    return app(RunAiConversationCycleAction::class)->handle($playerId, CarbonImmutable::parse(ESCALATION_NOW));
}

function escalationSentMessage(int $playerId): string|null
{
    return ChatMessage::query()->where('sender_id', $playerId)->latest('id')->value('message');
}

test('a routine acknowledgement is never escalated', function (AiSocialExchangeType $type): void {
    expect(app(ConversationRoutePolicy::class)->routeFor($type))->toBe(AiConversationRoute::Authored);
})->with('routine exchange types');

test('a substantive exchange may be realized by a provider', function (AiSocialExchangeType $type): void {
    expect(app(ConversationRoutePolicy::class)->routeFor($type))->toBe(AiConversationRoute::Realization);
})->with('substantive exchange types');

test('a substantive message is sealed and handed to the language lane instead of being sent', function (): void {
    Queue::fake();
    config(['ai.language.enabled' => true]);
    $human = $this->createUser();
    escalationProfile($this->currentUserId);
    escalationInboundMessage($this->currentUserId, $human->id, 'sorry about the raid, my bad');

    expect(runEscalationCycle($this->currentUserId))->toBe(1)
        ->and(escalationSentMessage($this->currentUserId))->toBeNull();

    $sealed = AiConversationReply::query()->where('player_id', $this->currentUserId)->sole();

    expect($sealed->state)->toBe(AiConversationReplyState::Sealed);

    Queue::assertPushedOn(
        AiQueueName::AiLanguage->value,
        GenerateAiReply::class,
        static fn (GenerateAiReply $job): bool => $job->replyId === $sealed->id,
    );
});

test('a greeting is sent authored and queues nothing', function (): void {
    Queue::fake();
    config(['ai.language.enabled' => true]);
    $human = $this->createUser();
    escalationProfile($this->currentUserId);
    escalationInboundMessage($this->currentUserId, $human->id, 'hello');

    expect(runEscalationCycle($this->currentUserId))->toBe(1)
        ->and(escalationSentMessage($this->currentUserId))->toBeIn(['Greetings.', 'Hello.'])
        ->and(AiConversationReply::query()->where('player_id', $this->currentUserId)->sole()->state)->toBe(AiConversationReplyState::Delivered);

    Queue::assertNotPushed(GenerateAiReply::class);
});

test('the default configuration answers the same exchange without any language work', function (): void {
    Queue::fake();
    config(['ai.language.enabled' => false]);
    $human = $this->createUser();
    escalationProfile($this->currentUserId);
    escalationInboundMessage($this->currentUserId, $human->id, 'sorry about the raid, my bad');

    expect(runEscalationCycle($this->currentUserId))->toBe(1)
        ->and(escalationSentMessage($this->currentUserId))->not->toBeNull()
        ->and(AiLanguageRequest::query()->count())->toBe(0);

    Queue::assertNotPushed(GenerateAiReply::class);
});

test('the lane job carries one reply, one attempt and a timeout under the worker bound', function (): void {
    config(['ai.language.timeout_seconds' => 17]);

    $job = app()->makeWith(GenerateAiReply::class, ['replyId' => 41]);

    expect($job->queue)->toBe(AiQueueName::AiLanguage->value)
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(47)
        ->and($job->tags())->toBe(['ai', 'ai:language', 'ai:reply:41']);
});

test('the lane job replaces the authored wording with the generated reply', function (): void {
    config(['ai.language.enabled' => true]);
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 metal.');
    bindFixedLanguageResult(languageResult(AiLanguageResultStatus::Completed, 'Generated wording.', 0, 0));

    app()->makeWith(GenerateAiReply::class, ['replyId' => $reply->id])->handle(app(GenerateAiReplyAction::class));

    expect(escalationSentMessage($this->currentUserId))->toBe('Generated wording.')
        ->and(AiLanguageRequest::query()->where('conversation_reply_id', $reply->id)->sole()->state)->toBe(AiLanguageRequestState::Completed);
});

test('the lane job answers with the authored reply when the provider is switched off', function (): void {
    config(['ai.language.enabled' => false]);
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 metal.');

    app()->makeWith(GenerateAiReply::class, ['replyId' => $reply->id])->handle(app(GenerateAiReplyAction::class));

    expect(escalationSentMessage($this->currentUserId))->toBe('Authored fallback.')
        ->and(AiLanguageRequest::query()->count())->toBe(0);
});

test('a job that dies before its receipt exists still answers with the authored reply', function (): void {
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'I owe you 500 metal.');

    app()->makeWith(GenerateAiReply::class, ['replyId' => $reply->id])->failed(new RuntimeException('worker killed'));

    expect(escalationSentMessage($this->currentUserId))->toBe('Authored fallback.')
        ->and(AiLanguageRequest::query()->count())->toBe(0);
});

test('an already automated counterparty never reaches a provider', function (): void {
    config(['ai.language.enabled' => true]);
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'sorry about the raid, my bad', true);

    app()->makeWith(GenerateAiReply::class, ['replyId' => $reply->id])->handle(app(GenerateAiReplyAction::class));

    expect(escalationSentMessage($this->currentUserId))->toBe('Authored fallback.')
        ->and(AiLanguageRequest::query()->count())->toBe(0);
});
