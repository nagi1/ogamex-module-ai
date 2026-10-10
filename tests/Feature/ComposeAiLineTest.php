<?php

use Modules\AI\Actions\ComposeAiLineAction;
use Modules\AI\Ai\Agents\OgameMessageAgent;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
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
    'it is too long' => [str_repeat('ENEM ', 60), false],
]);

test('with composition off the authored line is spoken and no model is asked', function (): void {
    config(['ai.language.compose' => false]);
    OgameMessageAgent::fake()->preventStrayPrompts();

    expect(app(ComposeAiLineAction::class)->handle($this->profile, 'k', 'plain line', []))->toBe('plain line');
    OgameMessageAgent::assertNeverPrompted();
});
