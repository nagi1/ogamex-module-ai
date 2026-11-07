<?php

use Modules\AI\Domain\Research\ResearchCeiling;
use OGame\Services\ObjectService;
use Tests\TestCase;

uses(TestCase::class);

it('caps a research paid in energy alone at the highest level the catalogue requires of it', function () {
    $ceiling = new ResearchCeiling();
    $required = [];
    foreach (ObjectService::getObjects() as $object) {
        foreach ($object->requirements as $requirement) {
            $required[$requirement->object_machine_name] = max($required[$requirement->object_machine_name] ?? 0, $requirement->level);
        }
    }

    $energyOnly = 0;
    foreach (ObjectService::getResearchObjects() as $research) {
        $price = $research->price->resources;
        $mined = $price->metal->get() + $price->crystal->get() + $price->deuterium->get();
        if ($mined > 0) {
            expect($ceiling->of($research))->toBeNull();

            continue;
        }

        $energyOnly++;
        $top = $required[$research->machine_name] ?? 0;
        expect($ceiling->of($research))->toBe($top)
            ->and($ceiling->allows($research, $top - 1))->toBeTrue()
            ->and($ceiling->allows($research, $top))->toBeFalse();
    }

    expect($energyOnly)->toBeGreaterThan(0);
});

it('leaves every unit and building without a ceiling', function () {
    $ceiling = new ResearchCeiling();
    foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getShipObjects(), ...ObjectService::getDefenseObjects()] as $object) {
        expect($ceiling->of($object))->toBeNull();
    }
});
