<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\ResolveAiUsageCostAction;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * A priced model matrix must price what it says it prices: the verified off-peak rate, the
 * peak multiplier decided by the vendor window, the cached-input column, and an honest null
 * for anything the matrix does not know.
 */
test('a known model prices its settled usage at the off-peak rate', function (): void {
    $cost = app(ResolveAiUsageCostAction::class)->handle(
        'deepseek',
        'deepseek-flash',
        1_000_000,
        0,
        1_000_000,
        CarbonImmutable::parse('2026-09-13 12:00:00', 'UTC'), // Sunday: never peak
    );

    expect($cost)->toBe(0.75);
});

test('a vendor inside its published peak window bills the peak multiplier', function (): void {
    $cost = app(ResolveAiUsageCostAction::class)->handle(
        'deepseek',
        'deepseek-flash',
        1_000_000,
        0,
        1_000_000,
        CarbonImmutable::parse('2026-09-14 02:00:00', 'UTC'), // Monday, inside 01:00-04:00
    );

    expect($cost)->toBe(1.5);
});

test('cached input tokens bill at the cached-input column', function (): void {
    $cost = app(ResolveAiUsageCostAction::class)->handle(
        'deepseek',
        'deepseek-flash',
        0,
        1_000_000,
        0,
        CarbonImmutable::parse('2026-09-13 12:00:00', 'UTC'),
    );

    expect($cost)->toBe(0.003);
});

test('a model the matrix does not know fails closed to null', function (): void {
    $cost = app(ResolveAiUsageCostAction::class)->handle(
        'openai',
        'gpt-5-mini', // never entered into the matrix
        1_000,
        0,
        1_000,
        CarbonImmutable::parse('2026-09-13 12:00:00', 'UTC'),
    );

    expect($cost)->toBeNull();
});

test('a provider with no published window is never peak', function (): void {
    $cost = app(ResolveAiUsageCostAction::class)->handle(
        'openai',
        'gpt-5.6-luna',
        1_000_000,
        0,
        1_000_000,
        CarbonImmutable::parse('2026-09-14 02:00:00', 'UTC'), // Monday peak hour, but openai has no window
    );

    expect($cost)->toBe(1.4);
});

test('negative or missing tokens fail closed to null', function (): void {
    $resolve = app(ResolveAiUsageCostAction::class);

    expect($resolve->handle('deepseek', 'deepseek-flash', -1, 0, 0, CarbonImmutable::parse('2026-09-13 12:00:00', 'UTC')))->toBeNull()
        ->and($resolve->handle(null, 'deepseek-flash', 100, 0, 0, CarbonImmutable::parse('2026-09-13 12:00:00', 'UTC')))->toBeNull()
        ->and($resolve->handle('deepseek', null, 100, 0, 0, CarbonImmutable::parse('2026-09-13 12:00:00', 'UTC')))->toBeNull();
});
