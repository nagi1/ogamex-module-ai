<?php

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SimulatedTime;

afterEach(function (): void {
    SimulatedTime::release();
});

test('freezing moves every clock the game reads', function () {
    $at = SimulatedTime::freezeAt('2026-10-05T03:00:00Z');

    expect(now()->toIso8601String())->toBe($at->toIso8601String())
        ->and(Date::now()->toIso8601String())->toBe($at->toIso8601String())
        ->and(Carbon::now()->toIso8601String())->toBe($at->toIso8601String())
        ->and(CarbonImmutable::now()->toIso8601String())->toBe($at->toIso8601String())
        ->and(app(AiClock::class)->now()->toIso8601String())->toBe($at->toIso8601String());
});

test('advancing jumps hours in no real time and release returns the wall clock', function () {
    SimulatedTime::freezeAt('2026-10-05T03:00:00Z');
    $after = SimulatedTime::advance(8 * 3600);

    expect($after->toIso8601String())->toBe('2026-10-05T11:00:00+00:00')
        ->and(now()->getTimestamp())->toBe($after->getTimestamp())
        ->and(SimulatedTime::isFrozen())->toBeTrue();

    SimulatedTime::release();

    expect(SimulatedTime::isFrozen())->toBeFalse()
        ->and(abs(now()->getTimestamp() - time()))->toBeLessThan(5);
});

test('the sim command refuses a database that is not a simulation copy', function () {
    $exit = Artisan::call('ai:sim', ['--hours' => 1]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Refusing to move');
});
