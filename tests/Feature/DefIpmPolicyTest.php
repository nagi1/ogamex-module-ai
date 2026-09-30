<?php

use Modules\AI\Domain\Defense\AntiBallisticMissile;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

it('stores ten anti-ballistic missiles per Missile Silo level', function () {
    $policy = AntiBallisticMissile::policy();

    expect($policy->abmCapacity(0))->toBe(0)
        ->and($policy->abmCapacity(1))->toBe(10)
        ->and($policy->abmCapacity(2))->toBe(20)
        ->and($policy->abmCapacity(3))->toBe(30);
});

it('keeps one interplanetary missile for every two anti-ballistic missiles a level stores', function () {
    $policy = AntiBallisticMissile::policy();

    [$ipmShare, $abmShare] = array_map('intval', explode(':', $policy->ipmToAbmCapacityRatio()));

    expect($policy->ipmToAbmCapacityRatio())->toBe('1:2')
        ->and($policy->ipmCapacity(0))->toBe(0)
        ->and($policy->ipmCapacity(1))->toBe(5)
        ->and($policy->ipmCapacity(3) * $abmShare)->toBe($policy->abmCapacity(3) * $ipmShare);
});

it('costs 8000 metal, 0 crystal and 2000 deuterium', function () {
    expect(AntiBallisticMissile::policy()->cost())->toBe([
        'metal' => 8000,
        'crystal' => 0,
        'deuterium' => 2000,
    ]);
});

it('needs Missile Silo level 2 before anti-ballistic missiles can be stored', function () {
    $policy = AntiBallisticMissile::policy();

    expect($policy->prerequisiteMissileSiloLevel())->toBe(2)
        ->and($policy->integrity())->toBe(8000)
        ->and($policy->shield())->toBe(1)
        ->and($policy->weapon())->toBe(1);
});
