<?php

use Modules\AI\Actions\ExportAiEconomyCatalogueAction;
use Modules\AI\Actions\ReplayAiEconomyScheduleAction;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl, rl/ogsim: the fast simulator reads nothing but these tables, so every value must be the host's own answer,
// the export must leave the planet as it found it, and the replay must play a build order through the host's queue.

/** @return list<GameObject> */
function catalogueTestObjects(): array
{
    return [...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()];
}

test('the catalogue holds every queueable building and station with the host\'s prices, times and production', function (): void {
    $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle([$this->planetService], 12);
    $exported = array_column($catalogue['objects'], null, 'id');
    $queueable = array_filter(catalogueTestObjects(), fn (GameObject $o): bool => ObjectService::objectValidPlanetType($o->machine_name, $this->planetService));

    expect(array_keys($exported))->toEqualCanonicalizing(array_map(static fn (GameObject $o): int => $o->id, $queueable));

    $mine = ObjectService::getObjectByMachineName('metal_mine');
    $price = ObjectService::getObjectRawPrice('metal_mine', 7);
    expect($exported[$mine->id]['price'][6])->toEqual([$price->metal->get(), $price->crystal->get(), $price->deuterium->get()]);

    $planet = $catalogue['planets'][0];
    $made = $this->planetService->getObjectProduction('metal_mine', 9, true);
    expect($planet['production'][(string) $mine->id][9])->toEqual([$made->metal->get(), $made->crystal->get(), $made->deuterium->get(), $made->energy->get()]);
});

test('every object that shortens build time is found by asking the host, and the time grid is the host\'s', function (): void {
    $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle([$this->planetService], 6);
    $modifiers = array_column($catalogue['time_modifiers'], 'id');
    $mine = ObjectService::getObjectByMachineName('metal_mine');

    foreach (catalogueTestObjects() as $object) {
        if ($object->id === $mine->id || ! ObjectService::objectValidPlanetType($object->machine_name, $this->planetService)) {
            continue;
        }
        $before = $this->planetService->getObjectLevel($object->machine_name);
        $this->planetService->setObjectLevel($mine->id, 30, false);
        $this->planetService->setObjectLevel($object->id, 0, false);
        $slow = $this->planetService->getBuildingConstructionTime('metal_mine');
        $this->planetService->setObjectLevel($object->id, 1, false);
        $fast = $this->planetService->getBuildingConstructionTime('metal_mine');
        $this->planetService->setObjectLevel($object->id, $before, false);

        expect(in_array($object->id, $modifiers, true))->toBe($fast !== $slow, $object->machine_name);
    }

    // The first cell of every grid row is the time with every modifier at zero, read back from the host.
    $row = array_column($catalogue['objects'], null, 'id')[$mine->id];
    foreach (catalogueTestObjects() as $object) {
        $this->planetService->setObjectLevel($object->id, 0, false);
    }
    $this->planetService->setObjectLevel($mine->id, 4, false);
    expect($row['build_seconds_grid'][4][0])->toBe($this->planetService->getBuildingConstructionTime('metal_mine'));
});

test('exporting leaves the planet\'s levels unchanged and saves nothing', function (): void {
    $before = Planet::query()->findOrFail($this->planetService->getPlanetId())->toArray();
    $levels = array_map(fn (GameObject $o): int => $this->planetService->getObjectLevel($o->machine_name), catalogueTestObjects());

    app(ExportAiEconomyCatalogueAction::class)->handle([$this->planetService], 4);

    expect(array_map(fn (GameObject $o): int => $this->planetService->getObjectLevel($o->machine_name), catalogueTestObjects()))->toBe($levels)
        ->and(Planet::query()->findOrFail($this->planetService->getPlanetId())->toArray())->toEqual($before);
});

test('a replayed build order goes through the host queue and reports the host\'s time and stock', function (): void {
    $mine = ObjectService::getObjectByMachineName('metal_mine');
    $level = $this->planetService->getObjectLevel('metal_mine');
    $this->planetAddResources(new Resources(100_000, 100_000, 0));
    $seconds = $this->planetService->getBuildingConstructionTime('metal_mine');

    $result = app(ReplayAiEconomyScheduleAction::class)->handle($this->planetService, [
        ['start' => 0.0, 'id' => $mine->id, 'end' => (float) $seconds, 'stock' => [0, 0, 0]],
    ], $seconds + 60);

    expect($result['steps'][0]['host_seconds'])->toBe($seconds)
        ->and($result['final']['levels'][$mine->id])->toBe($level + 1);
});
