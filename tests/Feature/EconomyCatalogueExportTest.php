<?php

use Modules\AI\Actions\ExportAiEconomyCatalogueAction;
use Modules\AI\Actions\ReplayAiEconomyScheduleAction;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
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

test('the research rows are the host\'s prices and times, and the lab level is found as a time modifier by asking', function (): void {
    $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle([$this->planetService], 6);
    $rows = array_column($catalogue['research'], null, 'id');

    expect($catalogue['version'])->toBe(3)
        ->and(array_keys($rows))->toEqualCanonicalizing(array_map(static fn (GameObject $o): int => $o->id, ObjectService::getResearchObjects()));

    $energy = ObjectService::getObjectByMachineName('energy_technology');
    $price = ObjectService::getObjectRawPrice('energy_technology', 4);
    expect($rows[$energy->id]['price'][3])->toEqual([$price->metal->get(), $price->crystal->get(), $price->deuterium->get()])
        ->and($rows[$energy->id]['consumes_field'])->toBeFalse();

    $lab = ObjectService::getObjectByMachineName('research_lab');
    expect(array_column($catalogue['time_modifiers_research'], 'id'))->toContain($lab->id);

    // The first grid cell is the host's time with every modifier at zero; the lab at 3 is the grid's own cell for level 4.
    $player = $this->planetService->getPlayer();
    $player->setResearchLevel('energy_technology', 3, false);
    foreach (catalogueTestObjects() as $object) {
        $this->planetService->setObjectLevel($object->id, 0, false);
    }
    expect($rows[$energy->id]['build_seconds_grid'][3][0])->toBe((int) $this->planetService->getTechnologyResearchTime('energy_technology'));
    $this->planetService->setObjectLevel($lab->id, 3, false);
    $modifierIds = array_column($catalogue['time_modifiers_research'], 'id');
    $cell = array_search($lab->id, $modifierIds, true);
    expect($modifierIds)->toHaveCount(1)->and($cell)->toBe(0)
        ->and($rows[$energy->id]['build_seconds_grid'][3][3])->toBe((int) $this->planetService->getTechnologyResearchTime('energy_technology'));
});

test('production_research lists exactly the researches that change a production row, found by asking', function (): void {
    $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle([$this->planetService], 5);
    $tabulated = $catalogue['planets'][0]['production_research'];
    $player = $this->planetService->getPlayer();

    foreach (ObjectService::getGameObjectsWithProduction() as $producer) {
        if (! in_array($producer->id, array_column($catalogue['objects'], 'id'), true)) {
            continue;
        }
        $changing = [];
        foreach (ObjectService::getResearchObjects() as $research) {
            $before = $player->getResearchLevel($research->machine_name);
            $player->setResearchLevel($research->machine_name, 0, false);
            $low = $this->planetService->getObjectProduction($producer->machine_name, 5, true);
            $player->setResearchLevel($research->machine_name, 1, false);
            $high = $this->planetService->getObjectProduction($producer->machine_name, 5, true);
            $player->setResearchLevel($research->machine_name, $before, false);
            if ([$low->metal->get(), $low->crystal->get(), $low->deuterium->get(), $low->energy->get()] !== [$high->metal->get(), $high->crystal->get(), $high->deuterium->get(), $high->energy->get()]) {
                $changing[] = $research->id;
            }
        }
        expect($tabulated[(string) $producer->id]['by'] ?? [])->toEqualCanonicalizing($changing, $producer->machine_name);
    }

    // A row equals the host's answer for that combination of research levels.
    $mine = ObjectService::getObjectByMachineName('metal_mine');
    $plasma = ObjectService::getObjectByMachineName('plasma_technology');
    expect($tabulated[(string) $mine->id]['by'])->toContain($plasma->id);
    $by = $tabulated[(string) $mine->id]['by'];
    $combo = 0;
    foreach ($by as $id) {
        $combo = $combo * 13 + ($id === $plasma->id ? 3 : 0);
    }
    foreach (catalogueTestObjects() as $object) {
        $this->planetService->setObjectLevel($object->id, 0, false);
    }
    foreach (ObjectService::getResearchObjects() as $research) {
        $player->setResearchLevel($research->machine_name, $research->id === $plasma->id ? 3 : 0, false);
    }
    $this->planetService->setObjectLevel($mine->id, 4, false);
    $made = $this->planetService->getObjectProduction('metal_mine', 4, true);
    expect($tabulated[(string) $mine->id]['rows'][4][$combo])->toEqual([$made->metal->get(), $made->crystal->get(), $made->deuterium->get(), $made->energy->get()])
        ->and($catalogue['planets'][0]['levels'])->toHaveKey((string) $plasma->id);
});

test('exporting leaves the research levels unchanged too', function (): void {
    $player = $this->planetService->getPlayer();
    $player->setResearchLevel('plasma_technology', 2, false);
    $before = array_map(static fn (GameObject $o): int => $player->getResearchLevel($o->machine_name), ObjectService::getResearchObjects());

    app(ExportAiEconomyCatalogueAction::class)->handle([$this->planetService], 3);

    expect(array_map(static fn (GameObject $o): int => $player->getResearchLevel($o->machine_name), ObjectService::getResearchObjects()))->toBe($before);
});

test('a replayed research step goes through the host research queue and reports the host\'s seconds', function (): void {
    $energy = ObjectService::getObjectByMachineName('energy_technology');
    $level = $this->planetService->getPlayer()->getResearchLevel('energy_technology');
    $this->planetService->setObjectLevel(ObjectService::getObjectByMachineName('research_lab')->id, 3, true);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $seconds = (int) $this->planetService->getTechnologyResearchTime('energy_technology');

    $result = app(ReplayAiEconomyScheduleAction::class)->handle($this->planetService, [
        ['start' => 0.0, 'id' => $energy->id, 'end' => (float) $seconds, 'stock' => [0, 0, 0], 'queue' => 'research'],
    ], $seconds + 60);

    expect($result['steps'][0]['host_seconds'])->toBe($seconds)
        ->and($result['final']['levels'][$energy->id])->toBe($level + 1);
});

test('a research decision step reports research candidate features with no production columns and the player\'s level', function (): void {
    $names = EconomyChoiceEncoder::candidateNames();
    $column = static fn (string $name): int => array_search($name, $names, true);
    $researchIds = array_map(static fn (GameObject $o): int => $o->id, ObjectService::getResearchObjects());
    $player = $this->planetService->getPlayer();
    $this->planetService->setObjectLevel(ObjectService::getObjectByMachineName('research_lab')->id, 3, true);
    $player->setResearchLevel('energy_technology', 3, false);
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    $energy = ObjectService::getObjectByMachineName('energy_technology');

    $result = app(ReplayAiEconomyScheduleAction::class)->handle($this->planetService, [
        ['start' => 10.0, 'decision' => 5.0, 'queue' => 'research', 'id' => $energy->id, 'end' => 20.0, 'stock' => [0, 0, 0]],
    ], 60, [], $researchIds);

    $cands = $result['steps'][0]['host_features']['cands'];
    expect($cands)->toHaveCount(count($researchIds));
    foreach ($cands as $i => $features) {
        $object = ObjectService::getObjectById($researchIds[$i]);
        expect($features[$column('type_research')])->toBe(1.0)
            ->and($features[$column('gain_ph')])->toBe(0.0)
            ->and($features[$column('energy_delta')])->toBe(0.0)
            ->and($features[$column('storage_gain')])->toBe(0.0)
            ->and($features[$column('level')])->toBe($player->getResearchLevel($object->machine_name) / 40);
    }
});
