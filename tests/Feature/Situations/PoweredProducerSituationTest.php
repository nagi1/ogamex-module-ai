<?php

use Modules\AI\Domain\Decision\FacilityChain;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function shortResourceProducers(OGame\Services\PlanetService $planet): array
{
    $producers = (new ReflectionMethod(FacilityChain::class, 'producersOfShortResources'))
        ->invoke(app(FacilityChain::class), 'robot_factory', $planet);

    return array_map(static fn ($object): string => $object->machine_name, $producers);
}

// A colony whose synthesizer already stands but yields nothing (no plant) is short of power, not of synthesizers:
// measured live 4 Oct 2026, level 9 on zero income with no mine and no plant.
test('the chain asks for a producer the planet lacks, and not for one that stands and yields nothing', function (): void {
    $this->planetAddResources(new OGame\Models\Resources(10_000, 10_000, 0));

    expect(shortResourceProducers($this->planetService))->toContain('deuterium_synthesizer');

    $this->planetSetObjectLevel('deuterium_synthesizer', 3);

    expect(shortResourceProducers($this->planetService))->not->toContain('deuterium_synthesizer');
});
