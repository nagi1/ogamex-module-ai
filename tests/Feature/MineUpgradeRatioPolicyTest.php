<?php

use Symfony\Component\Yaml\Yaml;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * @return array<string, mixed>
 */
function mineUpgradeRatioPolicy(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/mine-upgrade-ratio.yaml');
}

it('keeps the metal mine two levels above the crystal mine', function () {
    $policy = mineUpgradeRatioPolicy();

    expect($policy['levels'])->not->toBeEmpty();

    foreach ($policy['levels'] as $level) {
        expect($level['metal'])->toBe($level['crystal'] + $policy['metal_level_offset']);
    }
});

it('keeps one deuterium level per two crystal levels, rounded down', function () {
    $policy = mineUpgradeRatioPolicy();

    foreach ($policy['levels'] as $level) {
        expect($level['deuterium'])->toBe(intdiv($level['crystal'], $policy['crystal_levels_per_deuterium_level']));
    }
});

it('holds the source milestone at exactly 17 metal / 15 crystal / 10 deuterium', function () {
    $milestone = mineUpgradeRatioPolicy()['milestone'];

    expect($milestone['metal'])->toBe(17);
    expect($milestone['crystal'])->toBe(15);
    expect($milestone['deuterium'])->toBe(10);
});

it('does not recompute the milestone deuterium from the crystal ratio', function () {
    $policy = mineUpgradeRatioPolicy();
    $milestone = $policy['milestone'];

    // Crystal 15 would give 7 deuterium; the source states 10 and the milestone wins.
    expect($milestone['deuterium'])->not->toBe(intdiv($milestone['crystal'], $policy['crystal_levels_per_deuterium_level']));
});
