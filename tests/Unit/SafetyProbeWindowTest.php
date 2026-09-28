<?php

use Carbon\CarbonImmutable;
use Modules\AI\Support\SafetyProbeWindow;

it('probes one minute after the crash and returns ten minutes after it', function (): void {
    $window = SafetyProbeWindow::fromArrival(CarbonImmutable::parse('2026-09-28 23:00:00'));

    expect($window->probeArrival->format('Y-m-d H:i'))->toBe('2026-09-28 23:01')
        ->and($window->delayedReturn->format('Y-m-d H:i'))->toBe('2026-09-28 23:10');
});

it('shifts both timestamps with the arrival time instead of pinning a clock time', function (): void {
    $window = SafetyProbeWindow::fromArrival(CarbonImmutable::parse('2026-09-28 23:58:00'));

    expect($window->probeArrival->format('Y-m-d H:i'))->toBe('2026-09-28 23:59')
        ->and($window->delayedReturn->format('Y-m-d H:i'))->toBe('2026-09-29 00:08');
});

it('keeps the arrival time untouched', function (): void {
    $arrival = CarbonImmutable::parse('2026-09-28 23:00:00');

    SafetyProbeWindow::fromArrival($arrival);

    expect($arrival->format('Y-m-d H:i'))->toBe('2026-09-28 23:00');
});
