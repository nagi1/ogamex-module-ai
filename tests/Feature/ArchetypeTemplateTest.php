<?php

use Modules\AI\Domain\Doctrine\ArchetypeDoctrine;
use Modules\AI\Enums\AiArchetype;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// A template's small shares (a shield dome, a deathstar) used to wait behind the large ones forever; a unit the
// account owns none of now comes before more of one it already has.
test('a template unit the account owns none of comes before more of a large share', function (): void {
    $next = app(ArchetypeDoctrine::class)->nextTemplateUnit(
        AiArchetype::Hybrid,
        'defence_template',
        ['rocket_launcher' => 10, 'light_laser' => 0, 'heavy_laser' => 5, 'gauss_cannon' => 5, 'plasma_turret' => 5, 'small_shield_dome' => 1, 'anti_ballistic_missile' => 5],
        static fn (string $machineName): bool => in_array($machineName, ['rocket_launcher', 'large_shield_dome', 'small_shield_dome'], true),
    );

    expect($next)->toBe('large_shield_dome');
});
