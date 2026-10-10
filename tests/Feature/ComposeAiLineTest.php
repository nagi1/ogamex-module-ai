<?php

use Modules\AI\Actions\ComposeAiLineAction;
use Modules\AI\Ai\Agents\OgameMessageAgent;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Carbon\CarbonImmutable;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Models\AiAffectState;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    config([
        'ai.language.enabled' => true,
        'ai.language.compose' => true,
        'ai.language.provider' => 'openai',
        'ai.language.model' => 'gpt-5-mini',
    ]);
    $this->profile = AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Raider, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 3, 'enabled' => true]);
});

// The model words a line; the authored line is what is spoken whenever it drops a fact, is off or fails.
test('the model words the line, and the authored line stands when it drops a fact', function (string $said, bool $worded): void {
    OgameMessageAgent::fake([['text' => $said]]);
    $authored = 'We are at war with [ENEM]. Hit their planets.';

    $line = app(ComposeAiLineAction::class)->handle($this->profile, 'alliance_politics.war_broadcast', $authored, ['tag' => 'ENEM']);

    expect($line)->toBe($worded ? $said : $authored);
})->with([
    'it keeps the tag' => ['war on ENEM, everyone hit them and join the acs', true],
    'it loses the tag' => ['war with them, hit their planets', false],
    'it uses a long dash' => ['war on ENEM — everyone hit them', false],
    'it is too long' => [str_repeat('ENEM ', 60), false],
]);

test('with composition off the authored line is spoken and no model is asked', function (): void {
    config(['ai.language.compose' => false]);
    OgameMessageAgent::fake()->preventStrayPrompts();

    expect(app(ComposeAiLineAction::class)->handle($this->profile, 'k', 'plain line', []))->toBe('plain line');
    OgameMessageAgent::assertNeverPrompted();
});

// The engines' read of the other player reaches the prompt as plain words and never as scores.
test('the prompt carries how the account feels about the other player, in words', function (): void {
    $other = User::factory()->create();
    app(RecordAiRelationshipInteractionAction::class)->handle($this->currentUserId, $other->id, 92000, CarbonImmutable::now(), threatChange: 0.9);
    AiAffectState::query()->create(['player_id' => $this->currentUserId, 'emotion' => AiAffectEmotion::Anger, 'intensity' => 0.8, 'updated_for' => CarbonImmutable::now(), 'revision' => 1]);
    OgameMessageAgent::fake([['text' => 'no truce, ENEM hit me five times']]);

    app(ComposeAiLineAction::class)->handle($this->profile, 'ceasefire_request.reject', 'No truce. [ENEM] hit me.', ['tag' => 'ENEM'], $other->id);

    OgameMessageAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'very angry')
        && str_contains($prompt->prompt, 'treats them as an enemy')
        && !str_contains($prompt->prompt, '0.9'));
});
