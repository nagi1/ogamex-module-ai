<?php

use Carbon\CarbonImmutable;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Modules\AI\Actions\ReactAiToHumanMessageAction;
use Modules\AI\Actions\RunAiConversationCycleAction;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ReactAiToHumanMessage;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use OGame\Models\ChatMessage;
use OGame\Services\SettingsService;
use Modules\AI\Actions\DeliverAiDirectReplyAction;
use Modules\AI\Actions\GenerateAiReplyAction;
use Modules\AI\Actions\ReadAiTextAction;
use Modules\AI\Ai\Agents\OgameConversationReplyAgent;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\NativeContextBuilder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Infrastructure\Language\LaravelAiLanguageGateway;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiTextReading;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';
require_once __DIR__ . '/../Support/LanguageTestFixtures.php';

uses(IsolatedAccountTestCase::class);

// Jev through laravel/ai (llm-classifier-everywhere.md): one reader with the setting, the cap, the pause and the cache,
// and the outbound read that keeps a bot-sounding or leaking line from a human.

beforeEach(function (): void {
    config(['ai.classification.enabled' => true, 'ai.classification.daily_readings' => 100]);
});

function classifierQuestion(): array
{
    return ['bot' => new Boolean('Bot?')];
}

function classifierSpeaker(int $playerId): void
{
    AiProfile::query()->firstOrCreate(['player_id' => $playerId], [
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7,
        'enabled' => true,
    ]);
}

test('with the classifier off nothing is asked and the rules read the text', function (): void {
    config(['ai.classification.enabled' => false]);
    Classification::fake()->preventStrayClassifications();

    expect(app(ReadAiTextAction::class)->handle('test', 'hello', classifierQuestion()))->toBeNull();
    Classification::assertNothingClassified();
});

test('a text read once is served from the readings the second time', function (): void {
    Classification::fake([['bot' => new BooleanAnswer(0.9)]])->preventStrayClassifications();

    $first = app(ReadAiTextAction::class)->handle('test', 'hello there', classifierQuestion());
    $second = app(ReadAiTextAction::class)->handle('test', '  hello there ', classifierQuestion());

    expect($first?->isTrue('bot'))->toBeTrue()
        ->and($second?->id)->toBe($first?->id)
        ->and(AiTextReading::query()->count())->toBe(1);
});

test('the daily cap sends the rest of the day back to the rules', function (): void {
    config(['ai.classification.daily_readings' => 1]);
    Classification::fake([['bot' => new BooleanAnswer(0.1)], ['bot' => new BooleanAnswer(0.1)]]);

    app(ReadAiTextAction::class)->handle('test', 'one', classifierQuestion());

    expect(app(ReadAiTextAction::class)->handle('test', 'two', classifierQuestion()))->toBeNull();
});

test('a provider failure pauses the readers instead of paying a timeout per message', function (): void {
    $calls = 0;
    Classification::fake(function () use (&$calls): array {
        $calls++;
        throw new RuntimeException('down');
    });

    $first = app(ReadAiTextAction::class)->handle('test', 'one', classifierQuestion());
    $second = app(ReadAiTextAction::class)->handle('test', 'two', classifierQuestion());

    expect($first)->toBeNull()->and($second)->toBeNull()->and($calls)->toBe(1);
});

test('a line the classifier reads as a bot or a leak never reaches a human', function (float $bot, float $leak, bool $sent): void {
    classifierSpeaker($this->currentUserId);
    $human = User::factory()->create();
    Classification::fake([['bot' => new BooleanAnswer($bot), 'leak' => new BooleanAnswer($leak)]]);

    $message = app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $human->id, 'gg, nice hit');

    expect($message !== null)->toBe($sent);
})->with([
    'a plain line' => [0.2, 0.1, true],
    'a template a player would spot' => [0.95, 0.1, false],
    'a line naming the threat score' => [0.3, 0.9, false],
]);

test('a line to another AI account is never read', function (): void {
    classifierSpeaker($this->currentUserId);
    $other = User::factory()->create();
    classifierSpeaker($other->id);
    Classification::fake()->preventStrayClassifications();

    expect(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $other->id, 'hi'))->not->toBeNull();
    Classification::assertNothingClassified();
});

test('a provider reply the classifier reads as a bot keeps the authored line', function (): void {
    config(['ai.language.enabled' => true, 'ai.language.provider' => 'openai', 'ai.language.model' => 'gpt-5-mini']);
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, ['now' => CarbonImmutable::now()]));
    app()->bind(ContextBuilder::class, NativeContextBuilder::class);
    app()->bind(LanguageGateway::class, LaravelAiLanguageGateway::class);
    Queue::fake();
    [$reply] = sealedLanguageReply(fn () => $this->createUser(), $this->currentUserId, 'Can you answer this?');
    OgameConversationReplyAgent::fake([['text' => 'As an AI player I acknowledge your message.', 'interpretation' => 'none', 'candidates' => []]]);
    Classification::fake(fn ($prompt): array => str_contains($prompt->state, 'As an AI')
        ? ['bot' => new BooleanAnswer(0.97), 'leak' => new BooleanAnswer(0.9)]
        : ['bot' => new BooleanAnswer(0.1), 'leak' => new BooleanAnswer(0.0)]);

    expect(app(GenerateAiReplyAction::class)->handle($reply->id)?->message)->toBe('Authored fallback.');
});

/** A reading of a human message: the act, the urgency level (0 to 2) and the rest plain. */
function classifierInbound(string $act, int $urgency = 0): array
{
    $levels = [0.0, 0.0, 0.0];
    $levels[$urgency] = 1.0;

    return [
        'act' => new ChoiceAnswer($act, [$act => 0.9]),
        'urgency' => new ScoreAnswer((float) $urgency, $levels, ['day', 'hours', 'now']),
        'needs_reply' => new BooleanAnswer(0.9),
        'language' => new ChoiceAnswer('en', ['en' => 0.99]),
    ];
}

test('a human greeting in words the matcher does not know is answered as a greeting', function (): void {
    Queue::fake();
    app(SettingsService::class)->set('ai_language_enabled', '0');
    app()->bind(RandomSource::class, SeededRandomSource::class);
    app()->bind(SocialCognition::class, NativeSocialCognition::class);
    classifierSpeaker($this->currentUserId);
    $human = User::factory()->create();
    Classification::fake([classifierInbound('greeting')]);

    ChatMessage::create(['sender_id' => $human->id, 'recipient_id' => $this->currentUserId, 'message' => 'sup neighbour o/']);
    app(RunAiConversationCycleAction::class)->handle($this->currentUserId, CarbonImmutable::now());

    expect(AiSocialExchange::query()->where('player_id', $this->currentUserId)->value('type'))->toBe(AiSocialExchangeType::Greeting)
        ->and(AiTextReading::query()->where('purpose', 'inbound')->count())->toBe(1);
    Queue::assertPushed(ReactAiToHumanMessage::class);
});

test('a message from another AI account is never read by the classifier', function (): void {
    Queue::fake();
    classifierSpeaker($this->currentUserId);
    $other = User::factory()->create();
    classifierSpeaker($other->id);
    Classification::fake()->preventStrayClassifications();

    ChatMessage::create(['sender_id' => $other->id, 'recipient_id' => $this->currentUserId, 'message' => 'sup neighbour o/']);
    app(RunAiConversationCycleAction::class)->handle($this->currentUserId, CarbonImmutable::now());

    Classification::assertNothingClassified();
    Queue::assertNotPushed(ReactAiToHumanMessage::class);
});

test('an urgent human message brings the awake account\'s next session forward', function (int $urgency, bool $awake, bool $moved): void {
    Queue::fake();
    classifierSpeaker($this->currentUserId);
    $profile = AiProfile::query()->where('player_id', $this->currentUserId)->sole();
    $planner = app(SessionPlanner::class);
    $hour = collect(range(0, 47))->map(fn (int $h) => CarbonImmutable::parse('2026-10-10 00:00:00 UTC')->addHours($h))
        ->first(fn (CarbonImmutable $at): bool => $planner->isAwake($profile, $at->addMinutes(15)) === $awake && $planner->isAwake($profile, $at) === $awake);
    $this->travelTo($hour);
    AiSchedule::create(['player_id' => $this->currentUserId, 'timezone' => 'UTC', 'next_due_at' => now()->addHours(3), 'generation' => 2]);
    $session = AiWorkItem::create(['player_id' => $this->currentUserId, 'kind' => AiWorkKind::RunSession, 'due_at' => now()->addHours(3),
        'schedule_generation' => 2, 'idempotency_key' => 'session:' . $this->currentUserId . ':2', 'state' => AiWorkState::Pending]);
    $human = User::factory()->create();
    Classification::fake([classifierInbound('warning', $urgency)]);
    $message = ChatMessage::create(['sender_id' => $human->id, 'recipient_id' => $this->currentUserId, 'message' => 'fleet on your moon in 20 min, run']);

    app(ReactAiToHumanMessageAction::class)->handle($message->id);

    expect($session->fresh()->due_at->lessThan(now()->addMinutes(16)))->toBe($moved);
})->with([
    'a threat landing now, awake' => [2, true, true],
    'a message that can wait' => [0, true, false],
    'a threat landing now, asleep' => [2, false, false],
]);
