<?php

use Modules\AI\Ai\ResourceSaving\EarmarkLimitGuard;

function wik027Saving(array $queueItem = []): array
{
    $scenario = json_decode(
        (string) file_get_contents(__DIR__ . '/../../../resources/scenarios/def-stockpile-earmark-limit.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $saving = $scenario['saving'];
    $saving['queueItem'] = array_merge($saving['queueItem'], $queueItem);

    return $saving;
}

it('keeps the saving protected while the earmarking queue item is pending', function (string $type): void {
    $guard = app(EarmarkLimitGuard::class);
    $saving = wik027Saving(['status' => 'pending', 'type' => $type]);

    expect($guard->isProtected($saving))->toBeTrue();

    $plan = $guard->spendPlan($saving);

    expect($plan['metal'] ?? 0)->toBe(100)
        ->and($plan['crystal'] ?? 0)->toBe(50)
        ->and($plan['deuterium'] ?? 0)->toBe(0);
})->with(['building', 'research']);

it('reports the saving unprotected and emits no spend against the earmarked resources once the queue item has left the queue', function (string $status, string $type): void {
    $guard = app(EarmarkLimitGuard::class);
    $saving = wik027Saving(['status' => $status, 'type' => $type]);

    expect($guard->isProtected($saving))->toBeFalse();

    $plan = $guard->spendPlan($saving);

    expect($plan['metal'] ?? 0)->toBe(0)
        ->and($plan['crystal'] ?? 0)->toBe(0)
        ->and($plan['deuterium'] ?? 0)->toBe(0);
})->with([
    ['completed', 'building'],
    ['cancelled', 'building'],
    ['completed', 'research'],
    ['cancelled', 'research'],
]);

it('does not protect a saving whose queue item is gone', function (): void {
    $guard = app(EarmarkLimitGuard::class);
    $saving = wik027Saving();
    unset($saving['queueItem']);

    expect($guard->isProtected($saving))->toBeFalse()
        ->and($guard->spendPlan($saving))->toBe([]);
});
