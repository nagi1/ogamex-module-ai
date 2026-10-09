<?php

use Modules\AI\Actions\ReadAiHumanMessageAction;
use Modules\AI\Ai\Agents\OgameHumanTextAgent;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The act classifier was trained on our own authored lines (0.17 on human-style text, 10 Oct 2026); this writes the
// human-style messages, labelled by the act each was asked for, that it is trained and tested on instead.
test('the human text writer labels each message with the act it was asked for and drops repeats', function (): void {
    config(['ai.language.provider' => 'openai', 'ai.language.model' => 'gpt-5-mini']);
    $out = tempnam(sys_get_temp_dir(), 'human');
    OgameHumanTextAgent::fake(fn (): array => ['messages' => ['nap is off', 'Nap is off ', 'we are done, nap cancelled']]);

    $this->artisan('ai:write-human-texts', ['--per' => 3, '--rounds' => 1, '--out' => $out])->assertSuccessful();

    $rows = array_map(static fn (string $line): array => json_decode($line, true), file($out, FILE_IGNORE_NEW_LINES));
    unlink($out);

    // The fake answers every act with the same messages, so only the first act keeps them.
    expect(array_column($rows, 'text'))->toBe(['nap is off', 'we are done, nap cancelled'])
        ->and(array_unique(array_map(static fn (array $row): string => $row['answers']['act']['choice'], $rows)))->toBe(['greeting'])
        ->and(array_keys(ReadAiHumanMessageAction::ACTS))->toContain('acs_call', 'pact_break', 'bot_question');
});
