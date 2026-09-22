<?php

use Modules\AI\Actions\BuildAiOperationsPanelAction;
use Modules\AI\Actions\BuildAiSettingsPanelAction;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';

uses(AiQueueModuleTestCase::class);

test('every live setting has a four-line definition card', function (): void {
    $panel = app(BuildAiSettingsPanelAction::class)->handle();

    expect($panel['live'])->not->toBeEmpty();

    foreach ($panel['live'] as $setting) {
        expect($setting['definition'])->toHaveKeys(['what', 'why', 'effect', 'restart'])
            ->and($setting['definition']['what'])->not->toBe('')
            ->and($setting['definition']['why'])->not->toBe('')
            ->and($setting['definition']['effect'])->not->toBe('')
            ->and($setting['definition']['restart'])->not->toBe('');
    }
});

test('every operation has a four-line definition card', function (): void {
    $panel = app(BuildAiOperationsPanelAction::class)->handle();

    expect($panel['operations'])->not->toBeEmpty();

    foreach ($panel['operations'] as $operation) {
        expect($panel['definitions'])->toHaveKey($operation->value)
            ->and($panel['definitions'][$operation->value]['what'])->not->toBe('')
            ->and($panel['definitions'][$operation->value]['why'])->not->toBe('')
            ->and($panel['definitions'][$operation->value]['effect'])->not->toBe('')
            ->and($panel['definitions'][$operation->value]['restart'])->not->toBe('');
    }
});
