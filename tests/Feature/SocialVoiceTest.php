<?php

use Carbon\CarbonImmutable;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Modules\AI\Actions\BuildAuthoredSocialReplyAction;
use Modules\AI\Actions\EvaluateAiSocialExchangeAction;
use Modules\AI\Actions\RecordAiSocialExchangeAction;
use Modules\AI\Ai\Agents\OgameVoiceAgent;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Symfony\Component\Yaml\Yaml;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Persona voices (llm-classifier-everywhere.md, step 7): drafted offline, each line checked, and read before the
// default lines so accounts do not all answer with the same handful.

test('the voice writer keeps only lines the classifier lets through, for keys the defaults have', function (): void {
    config(['ai.language.provider' => 'openai', 'ai.language.model' => 'gpt-5-mini']);
    $out = tempnam(sys_get_temp_dir(), 'voices');
    OgameVoiceAgent::fake([['keys' => [
        ['key' => 'greeting', 'lines' => ['sup', 'Greetings, fellow player. I am online.']],
        ['key' => 'invented_key', 'lines' => ['whatever']],
    ]]]);
    Classification::fake(fn ($prompt): array => str_contains((string) $prompt->state, 'Greetings, fellow')
        ? ['bot' => new BooleanAnswer(0.95), 'leak' => new BooleanAnswer(0.1)]
        : ['bot' => new BooleanAnswer(0.1), 'leak' => new BooleanAnswer(0.0)]);

    $this->artisan('ai:write-social-voices', ['archetypes' => ['raider'], '--out' => $out])->assertSuccessful();

    expect(Yaml::parseFile($out))->toBe(['raider' => ['greeting' => ['sup']]]);
    unlink($out);
});

test('an account with a voice answers in it, the others in the default lines', function (): void {
    $path = dirname(__DIR__, 2) . BuildAuthoredSocialReplyAction::VOICES;
    $existing = is_file($path) ? file_get_contents($path) : null;
    file_put_contents($path, Yaml::dump(['turtle' => ['greeting' => ['evening, neighbour']]]));

    try {
        $profile = AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Turtle, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 3, 'enabled' => true]);
        $counterparty = $this->createUser();
        $observation = AiObservation::query()->create(['player_id' => $this->currentUserId, 'source_type' => AiObservationSource::ChatMessage, 'source_id' => 990_001,
            'kind' => AiObservationKind::DirectChatMessageReceived, 'subject_player_id' => $counterparty->id, 'source_time' => now(), 'observed_at' => now()]);
        $recorded = app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $counterparty->id, $observation->id, AiSocialExchangeType::Greeting, []);
        $exchange = app(EvaluateAiSocialExchangeAction::class)->handle($recorded->id, 0, CarbonImmutable::now());
        $voiced = app(BuildAuthoredSocialReplyAction::class)->handle($exchange, $profile);
        $profile->update(['archetype' => AiArchetype::Miner]);
        $default = app(BuildAuthoredSocialReplyAction::class)->handle($exchange, $profile->fresh());
    } finally {
        $existing === null ? unlink($path) : file_put_contents($path, $existing);
    }

    expect($voiced)->toBe('evening, neighbour')
        ->and($default)->toBeIn(['hey', 'hi o/', 'hey there', 'yo']);
});
