<?php

use Modules\AI\Actions\ExportAiEconomyCatalogueAction;
use Modules\AI\Actions\ReplayAiEconomyScheduleAction;
use Modules\AI\Actions\ReplayAiWorldAction;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\CharacterClassService;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
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

test('every field-adding object is found by asking the host, and the base is the planet with nothing built', function (): void {
    $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle([$this->planetService], 6);
    $exported = array_column($catalogue['objects'], null, 'id');
    $adders = 0;

    foreach (catalogueTestObjects() as $object) {
        if (! ObjectService::objectValidPlanetType($object->machine_name, $this->planetService)) {
            continue;
        }
        $before = $this->planetService->getObjectLevel($object->machine_name);
        $this->planetService->setObjectLevel($object->id, 0, false);
        $bare = $this->planetService->getPlanetFieldMax();
        $this->planetService->setObjectLevel($object->id, 2, false);
        $raised = $this->planetService->getPlanetFieldMax() - $bare;
        $this->planetService->setObjectLevel($object->id, $before, false);

        expect($exported[$object->id]['fields_added'][2] ?? 0)->toBe($raised, $object->machine_name);
        $adders += $raised > 0 ? 1 : 0;
    }

    // The table has to be checkable: at least one object on a real planet adds fields, and the planet's own entry is the
    // fields it has with nothing built on it, which is what the simulator adds the column to.
    expect($adders)->toBeGreaterThan(0, 'no object adds a field on this planet, so the probe cannot be checked');
    foreach (catalogueTestObjects() as $object) {
        $this->planetService->setObjectLevel($object->id, 0, false);
    }
    expect($catalogue['planets'][0]['field_max'])->toBe($this->planetService->getPlanetFieldMax());
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

    expect($catalogue['version'])->toBe(4)
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

test('a replayed yard order reports what the host queued, and nothing while an object that blocks the yard upgrades', function (): void {
    $shipyard = ObjectService::getObjectByMachineName('shipyard');
    $launcher = ObjectService::getObjectByMachineName('rocket_launcher');
    $this->planetService->setObjectLevel(ObjectService::getObjectByMachineName('robot_factory')->id, 2, true);
    $this->planetService->setObjectLevel($shipyard->id, 2, true);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $order = ['start' => 0.0, 'id' => $launcher->id, 'amount' => 3, 'queued' => 3, 'queue' => 'units'];

    $placed = app(ReplayAiEconomyScheduleAction::class)->handle($this->planetService, [$order], 60, yardBlockedBy: [$shipyard->id]);
    $upgrade = ['start' => 100.0, 'id' => $shipyard->id, 'end' => 200.0, 'stock' => [0, 0, 0]];
    $refused = app(ReplayAiEconomyScheduleAction::class)->handle($this->planetService, [$upgrade, ['start' => 101.0] + $order], 160, yardBlockedBy: [$shipyard->id]);

    expect($placed['steps'][0]['host_queued'])->toBe(3)
        ->and($refused['steps'][0]['host_seconds'])->not->toBeNull()
        ->and($refused['steps'][1]['host_queued'])->toBe(0);
});

test('a replayed account sends its colony ship, founds the colony with the simulator\'s draw and builds on it', function (): void {
    // The test account holds two planets already.
    $this->playerSetResearchLevel('astrophysics', 3);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddResources(new Resources(0, 0, 100_000));
    $home = $this->planetService->getPlanetCoordinates();
    $to = $this->getNearbyEmptyCoordinate();
    $mine = ObjectService::getObjectByMachineName('metal_mine');
    $ship = ObjectService::getObjectByMachineName('colony_ship');
    $capital = ['planet_id' => $this->planetService->getPlanetId(), 'account' => $this->planetService->getPlanetId(), 'coord' => [$home->galaxy, $home->system, $home->position], 'site' => null, 'final' => [], 'steps' => [
        ['t' => 0.0, 'queue' => 'launch', 'fleet' => 0, 'mission' => 7, 'to' => [$to->galaxy, $to->system, $to->position], 'ships' => [[$ship->id, 1]], 'cargo' => [0, 0, 0], 'speed' => 10.0, 'fuel' => 0, 'arrive' => 1.0],
        ['t' => 100_000.0, 'queue' => 'arrive', 'fleet' => 0, 'outcome' => 'Done'],
    ]];
    $colony = ['planet_id' => null, 'account' => $this->planetService->getPlanetId(), 'coord' => [$to->galaxy, $to->system, $to->position], 'site' => ['temp_max' => 37, 'field_max' => 171], 'final' => [], 'steps' => [
        ['t' => 100_100.0, 'queue' => 'building', 'id' => $mine->id, 'end' => 100_200.0, 'stock' => [0, 0, 0]],
    ]];

    [$home, $founded] = app(ReplayAiWorldAction::class)->handle([$capital, $colony], 100_300, [], 100_000);

    $planet = Planet::query()->find($founded['planet_id']);
    expect($home['steps'][0]['host_refusal'])->toBeNull()
        ->and($home['steps'][1]['host_outcome'])->toBe('Done')
        ->and([$planet->temp_max, $planet->temp_min, $planet->field_max])->toBe([37, -3, 171])
        ->and($founded['steps'][0]['host_seconds'])->not->toBeNull()
        ->and($founded['host_final']['levels'][$mine->id])->toBe(1);
});

test('a replayed recall turns the fleet home in the time it had flown', function (): void {
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddResources(new Resources(0, 0, 10_000));
    $home = $this->planetService->getPlanetCoordinates();
    $to = $this->secondPlanetService->getPlanetCoordinates();
    $ship = ObjectService::getObjectByMachineName('small_cargo');
    $capital = ['planet_id' => $this->planetService->getPlanetId(), 'account' => $this->planetService->getPlanetId(), 'coord' => [$home->galaxy, $home->system, $home->position], 'site' => null, 'final' => [], 'steps' => [
        ['t' => 0.0, 'queue' => 'launch', 'fleet' => 0, 'mission' => 3, 'to' => [$to->galaxy, $to->system, $to->position], 'ships' => [[$ship->id, 1]], 'cargo' => [0, 0, 0], 'speed' => 10.0, 'fuel' => 0, 'arrive' => 1.0],
        ['t' => 10.0, 'queue' => 'recall', 'fleet' => 0],
        ['t' => 20.0, 'queue' => 'return', 'fleet' => 0, 'outcome' => 'Done', 'gained' => [0, 0, 0]],
    ]];

    [$home] = app(ReplayAiWorldAction::class)->handle([$capital], 100, [], 100_000);

    expect($home['steps'][1]['host_refusal'])->toBeNull()
        ->and($home['steps'][2]['host_outcome'])->toBe('Done')
        ->and($home['steps'][2]['host_at'])->toBe(20);
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

test('the units that move production are tabulated by count and put back where they were', function (): void {
    $units = ObjectService::getUnitObjects();
    $held = array_map(fn ($unit): int => $this->planetService->getObjectAmount($unit->machine_name), $units);

    $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle([$this->planetService], 4, 8);

    expect($catalogue['version'])->toBe(4)
        ->and($catalogue['units'])->not->toBeEmpty()
        ->and($catalogue['unit_probes'])->not->toBeEmpty()
        ->and(array_map(fn ($unit): int => $this->planetService->getObjectAmount($unit->machine_name), $units))->toBe($held);

    $probe = $catalogue['unit_probes'][0];
    expect($probe['answer'])->toHaveCount(6)
        ->and($probe['answer'][5])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and(count($probe['levels']))->toBeGreaterThan(0);

    // One table per moving unit, in the same order, each answering at every count it is used at.
    $planet = $catalogue['planets'][0];
    expect(array_column($planet['unit_production'], 'id'))->toBe($catalogue['units']);
    $tabulated = array_filter($planet['unit_production'], fn (array $table): bool => $table['energy'] !== [] || $table['own'] !== []);
    expect($tabulated)->not->toBeEmpty();
    foreach ($tabulated as $table) {
        expect($table['own'] === null || count($table['own']) > 1)->toBeTrue()
            ->and($table['energy'] === [] || count($table['energy']) > 1)->toBeTrue();
    }
});

test('a colony is tabulated as the donor planet moved to its slot, and the planet is left where it was', function (): void {
    $planet = $this->planetService;
    $position = $planet->getPlanetCoordinates()->position;
    $temperature = $planet->getPlanetTempMax();
    $fields = $planet->getPlanetFieldMax();
    $other = $position === 8 ? 9 : 8;
    $site = ['planet' => $planet->getPlanetId(), 'position' => $other, 'temp_max' => 31, 'fields' => 123];

    $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle([$planet], 3, 8, [$site]);

    $colonisation = $catalogue['colonisation'];
    expect(count($catalogue['planets']))->toBe(2)
        ->and($colonisation['ship'])->toBe(ObjectService::getUnitObjectByMachineName('colony_ship')->id)
        ->and($colonisation['research'])->toBe(ObjectService::getResearchObjectByMachineName('astrophysics')->id)
        ->and($colonisation['max_planets'])->toHaveCount(4)
        ->and($colonisation['max_planets'][0])->toBe(1)
        ->and($colonisation['max_expeditions'])->toHaveCount(4)
        ->and($colonisation['max_expeditions'][1])->toBe($colonisation['max_expeditions'][0] + 1)
        ->and($colonisation['sites'])->toBe([['config' => 1, 'donor' => $planet->getPlanetId(), 'position' => $other, 'temp_max' => 31, 'fields' => 123]]);

    $colony = $catalogue['planets'][1];
    expect($colony['planet_id'])->toBeLessThan(0)
        ->and($colony['position'])->toBe($other)
        ->and($colony['temperature'])->toBe(31 - 20)
        ->and($colony['field_max'])->toBe(123)
        ->and($colony['resources'])->toEqual([500, 500, 0])
        ->and(array_sum(array_intersect_key($colony['levels'], array_flip(array_column($catalogue['objects'], 'id')))))->toBe(0);

    // The donor is untouched.
    expect($planet->getPlanetCoordinates()->position)->toBe($position)
        ->and($planet->getPlanetTempMax())->toBe($temperature)
        ->and($planet->getPlanetFieldMax())->toBe($fields)
        ->and($catalogue['planets'][0]['position'])->toBe($position);
});

test('every planet carries its owner\'s character class, a colony its donor\'s', function (): void {
    $planet = $this->planetService;
    $user = $planet->getPlayer()->getUser();
    $user->character_class = CharacterClass::DISCOVERER->value;
    $site = ['planet' => $planet->getPlanetId(), 'position' => $planet->getPlanetCoordinates()->position === 8 ? 9 : 8, 'temp_max' => 31, 'fields' => 123];

    $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle([$planet], 3, 8, [$site]);

    expect(array_column($catalogue['planets'], 'character_class'))->toBe([CharacterClass::DISCOVERER->value, CharacterClass::DISCOVERER->value]);
});

test('the export carries the host\'s fleet slots, research time per class, colony sizes and research network', function (): void {
    $planet = $this->planetService;
    $player = $planet->getPlayer();
    $user = $player->getUser();
    $user->character_class = CharacterClass::COLLECTOR->value;

    $catalogue = app(ExportAiEconomyCatalogueAction::class)->handle([$planet], 3, 8, []);
    $colonisation = $catalogue['colonisation'];
    $fleetResearch = ObjectService::getObjectById($colonisation['fleet_research']);
    $slots = [];
    foreach (range(0, 3) as $level) {
        $player->setResearchLevel($fleetResearch->machine_name, $level, false);
        $slots[] = $player->getFleetSlotsMax();
    }
    $classes = app(CharacterClassService::class);
    $multipliers = [];
    foreach (CharacterClass::cases() as $class) {
        $multipliers[$class->value] = $classes->getResearchTimeMultiplier((new User())->forceFill(['character_class' => $class->value]));
    }

    expect($colonisation['max_fleets'])->toBe($slots)
        ->and($catalogue['research_time_multipliers'])->toBe($multipliers)
        ->and($colonisation['field_ranges'][8])->toBe(array_map(static fn (int $n): int => $n + app(SettingsService::class)->planetFieldsBonus(), app(PlanetServiceFactory::class)->planetData(8, false)['fields']))
        ->and(ObjectService::getObjectById($catalogue['research_network'])->type)->toBe(GameObjectType::Research);
});
