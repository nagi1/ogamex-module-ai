<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\RecommendResourceHiding;

// Loaded explicitly so the test does not depend on the suite's autoload path prefixes.
require_once __DIR__ . '/../../app/Ai/Actions/RecommendResourceHiding.php';

it('names the available sink and the spend amount when it finishes before the arrival', function () {
    $arrival = CarbonImmutable::parse('2026-09-28 21:30:00');

    $result = app(RecommendResourceHiding::class)->execute(
        [['id' => 7, 'arrival_at' => $arrival]],
        [['id' => 3, 'type' => 'build_queue', 'completes_at' => $arrival->subSecond()]],
        12500,
    );

    expect($result)->not->toBeNull()
        ->and($result['sink']['id'])->toBe(3)
        ->and($result['sink']['type'])->toBe('build_queue')
        ->and($result['amount'])->toBe(12500);
});

it('stays silent when the sink finishes exactly at the arrival', function () {
    $arrival = CarbonImmutable::parse('2026-09-28 21:30:00');

    $result = app(RecommendResourceHiding::class)->execute(
        [['id' => 7, 'arrival_at' => $arrival]],
        [['id' => 3, 'type' => 'build_queue', 'completes_at' => $arrival]],
        12500,
    );

    expect($result)->toBeNull();
});

it('stays silent when every sink finishes after the arrival', function () {
    $arrival = CarbonImmutable::parse('2026-09-28 21:30:00');

    $result = app(RecommendResourceHiding::class)->execute(
        [['id' => 7, 'arrival_at' => $arrival]],
        [
            ['id' => 3, 'type' => 'research', 'completes_at' => $arrival->addSecond()],
            ['id' => 4, 'type' => 'shipyard', 'completes_at' => $arrival->addHour()],
        ],
        12500,
    );

    expect($result)->toBeNull();
});

it('races the earliest known arrival when several hostile fleets are inbound', function () {
    $action = app(RecommendResourceHiding::class);

    $earliest = CarbonImmutable::parse('2026-09-28 21:20:00');
    $later = CarbonImmutable::parse('2026-09-28 22:00:00');

    $inbound = [
        ['id' => 11, 'arrival_at' => $later],
        ['id' => 12, 'arrival_at' => $earliest],
    ];

    $tooLate = $action->execute(
        $inbound,
        [['id' => 4, 'type' => 'shipyard', 'completes_at' => $earliest->addMinute()]],
        900,
    );

    $inTime = $action->execute(
        $inbound,
        [['id' => 4, 'type' => 'shipyard', 'completes_at' => $earliest->subMinute()]],
        900,
    );

    expect($tooLate)->toBeNull()
        ->and($inTime)->not->toBeNull()
        ->and($inTime['sink']['id'])->toBe(4)
        ->and($inTime['amount'])->toBe(900);
});

it('stays silent when no inbound fleet has a known arrival time', function () {
    $result = app(RecommendResourceHiding::class)->execute(
        [['id' => 7, 'arrival_at' => null]],
        [['id' => 3, 'type' => 'build_queue', 'completes_at' => CarbonImmutable::parse('2026-09-28 21:10:00')]],
        12500,
    );

    expect($result)->toBeNull();
});

it('stays silent when no inbound hostile fleet exists', function () {
    $result = app(RecommendResourceHiding::class)->execute(
        [],
        [['id' => 3, 'type' => 'build_queue', 'completes_at' => CarbonImmutable::parse('2026-09-28 21:10:00')]],
        12500,
    );

    expect($result)->toBeNull();
});

it('stays silent when the stockpile is zero', function () {
    $arrival = CarbonImmutable::parse('2026-09-28 21:30:00');

    $result = app(RecommendResourceHiding::class)->execute(
        [['id' => 7, 'arrival_at' => $arrival]],
        [['id' => 3, 'type' => 'build_queue', 'completes_at' => $arrival->subMinute()]],
        0,
    );

    expect($result)->toBeNull();
});

it('names the first in-time sink in the order the caller supplied', function () {
    $arrival = CarbonImmutable::parse('2026-09-28 21:30:00');

    $result = app(RecommendResourceHiding::class)->execute(
        [['id' => 7, 'arrival_at' => $arrival]],
        [
            ['id' => 21, 'type' => 'research', 'completes_at' => $arrival->subMinutes(5)],
            ['id' => 22, 'type' => 'build_queue', 'completes_at' => $arrival->subMinutes(3)],
        ],
        500,
    );

    expect($result)->not->toBeNull()
        ->and($result['sink']['id'])->toBe(21)
        ->and($result['amount'])->toBe(500);
});
