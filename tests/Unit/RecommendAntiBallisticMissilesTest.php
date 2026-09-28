<?php

use Modules\AI\Actions\RecommendAntiBallisticMissiles;

it('recommends two anti-ballistic missiles per plasma turret while below the cap', function (): void {
    $action = app(RecommendAntiBallisticMissiles::class);

    expect($action->recommend(1))->toBe(2);
    expect($action->recommend(10))->toBe(20);
});

it('recommends no anti-ballistic missiles when there are no plasma turrets', function (): void {
    expect(app(RecommendAntiBallisticMissiles::class)->recommend(0))->toBe(0);
});

it('reaches the 70 cap exactly where the ratio meets it', function (): void {
    expect(app(RecommendAntiBallisticMissiles::class)->recommend(35))->toBe(70);
});

it('stops at the 70 cap once the ratio exceeds it', function (): void {
    $action = app(RecommendAntiBallisticMissiles::class);

    expect($action->recommend(36))->toBe(70);
    expect($action->recommend(40))->toBe(70);
    expect($action->recommend(100))->toBe(70);
});
