<?php

use Modules\AI\Support\AiRuntimeSettings;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// SettingsService caches the table in a singleton; drop it between tests so one test's
// write cannot leak into another through the in-memory cache.
afterEach(function () {
    app()->forgetInstance(AiRuntimeSettings::class);
    app()->forgetInstance(SettingsService::class);
});

it('resolves the reviewed defaults when nothing is written', function () {
    $settings = app(AiRuntimeSettings::class);

    expect($settings->profileCap())->toBe(0)
        ->and($settings->dispatchBatchSize())->toBe(100)
        ->and($settings->sessionActionCap())->toBe(1)
        ->and($settings->languageEnabled())->toBeTrue()
        ->and($settings->languageAiToAi())->toBeFalse()
        ->and($settings->monthlyCostUsd())->toBe(10.0)
        ->and($settings->conversationReplyTtlMinutes())->toBe(180)
        ->and($settings->affectDecisionWeight())->toBe(10)
        ->and($settings->campaignMode()->value)->toBe('off');
});

it('reads a changed value on the next read, with no restart', function () {
    app(SettingsService::class)->set('ai_population_profile_cap', '5');
    app(SettingsService::class)->set('ai_monthly_cost_usd', '25');
    app(SettingsService::class)->set('ai_language_enabled', '0');

    $settings = app(AiRuntimeSettings::class);

    expect($settings->profileCap())->toBe(5)
        ->and($settings->monthlyCostUsd())->toBe(25.0)
        ->and($settings->languageEnabled())->toBeFalse();
});

it('resolves the campaign reconciliation window', function () {
    expect(app(AiRuntimeSettings::class)->campaignReconciliationMinutes())->toBe(30);
});
