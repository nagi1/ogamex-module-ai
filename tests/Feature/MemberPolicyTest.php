<?php

use Symfony\Component\Yaml\Yaml;

/**
 * The relation policy the account reads before it picks a target. Alliance members ship as
 * non-hostile, so a member is never returned as an attack target, while a player with no
 * membership edge at the same coordinates keeps the account's attack option.
 */
function memberRelationPolicy(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/member.yaml');
}

function relationIsHostile(array $policy, string $relation): bool
{
    return ($policy[$relation]['hostile'] ?? true) === true;
}

it('classifies a member of the acting alliance as non-hostile', function () {
    $policy = memberRelationPolicy();

    expect($policy['member'])->toHaveKey('hostile')
        ->and(relationIsHostile($policy, 'member'))->toBeFalse();
});

it('never returns a member as an attack target and leaves a stranger at the same coordinates attackable', function () {
    $policy = memberRelationPolicy();

    $candidates = [
        ['relation' => 'member', 'coordinates' => '2:2:2'],
        ['relation' => 'stranger', 'coordinates' => '2:2:2'],
        ['relation' => 'member', 'coordinates' => '9:9:9'],
    ];

    $targets = array_values(array_filter(
        $candidates,
        fn (array $candidate): bool => relationIsHostile($policy, $candidate['relation']),
    ));

    expect($targets)->toBe([['relation' => 'stranger', 'coordinates' => '2:2:2']]);
});
