<?php

use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\ExportAiUnitsCatalogueAction;
use Modules\AI\Actions\ReplayAiUnitOrdersAction;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\UnitObject;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl/world-sim-spec.md, units: the simulator's shipyard reads nothing but these tables, so every cell must be the
// host's own answer, every cap must be found by asking the host, and the export must leave the planet as it found it.

dataset('shipyard levels', [
    'bare' => [0, 0],
    'shipyard only' => [5, 0],
    'nanite only' => [0, 2],
    'both' => [3, 4],
]);

test('every ship and defence is exported with the host\'s price and requirements', function (): void {
    $catalogue = app(ExportAiUnitsCatalogueAction::class)->handle([$this->planetService], 4);
    $units = array_column($catalogue['units'], null, 'id');

    expect($catalogue['version'])->toBe(1)
        ->and(array_keys($units))->toEqualCanonicalizing(array_map(static fn (UnitObject $u): int => $u->id, ObjectService::getUnitObjects()));

    foreach (ObjectService::getUnitObjects() as $unit) {
        $price = ObjectService::getObjectRawPrice($unit->machine_name);
        expect($units[$unit->id]['price'])->toEqual([$price->metal->get(), $price->crystal->get(), $price->deuterium->get()], $unit->machine_name)
            ->and(array_column($units[$unit->id]['requirements'], 'level'))->toBe(array_map(static fn ($r): int => $r->level, $unit->requirements));
    }
});

test('the objects that set unit time are found by asking the host, and the grid is the host\'s seconds', function (int $shipyard, int $nanite): void {
    $catalogue = app(ExportAiUnitsCatalogueAction::class)->handle([$this->planetService], 5);
    $id = static fn (string $name): int => ObjectService::getObjectByMachineName($name)->id;

    expect($catalogue['time_modifiers'])->toBe([$id('shipyard'), $id('nano_factory')])
        ->and($catalogue['blocked_while_upgrading'])->toBe($catalogue['time_modifiers']);

    $this->planetService->setObjectLevel($id('shipyard'), $shipyard, false);
    $this->planetService->setObjectLevel($id('nano_factory'), $nanite, false);
    foreach ($catalogue['units'] as $row) {
        $expected = $this->planetService->getUnitConstructionTime(ObjectService::getObjectById($row['id'])->machine_name);
        expect($row['build_seconds_grid'][$shipyard * 6 + $nanite])->toBe($expected, $row['machine_name']);
    }
})->with('shipyard levels');

test('owned limits, store slots and production coupling are the host\'s caps', function (): void {
    $catalogue = app(ExportAiUnitsCatalogueAction::class)->handle([$this->planetService], 2);
    $units = array_column($catalogue['units'], null, 'machine_name');
    $silo = ObjectService::getObjectByMachineName('missile_silo')->id;

    expect($units['small_shield_dome']['owned_limit'])->toBe(1)
        ->and($units['large_shield_dome']['owned_limit'])->toBe(1)
        ->and($units['anti_ballistic_missile']['store'])->toBe(['id' => $silo, 'slots' => 1])
        ->and($units['interplanetary_missile']['store'])->toBe(['id' => $silo, 'slots' => 2])
        ->and($catalogue['stores'])->toBe([['id' => $silo, 'capacity_per_level' => 10]])
        ->and($units['light_fighter']['owned_limit'])->toBeNull()
        ->and($units['light_fighter']['store'])->toBeNull()
        ->and($units['solar_satellite']['moves_production'])->toBeTrue()
        ->and($units['light_fighter']['moves_production'])->toBeFalse();
});

test('each planet carries its units and the units its owner\'s class may build', function (): void {
    $this->planetService->addUnit('light_fighter', 7, false);
    $catalogue = app(ExportAiUnitsCatalogueAction::class)->handle([$this->planetService], 1);
    $planet = $catalogue['planets'][0];
    $allowed = array_values(array_filter(ObjectService::getUnitObjects(), fn (UnitObject $u): bool => ObjectService::objectCharacterClassMet($u->machine_name, $this->planetService)));

    expect($planet['planet_id'])->toBe($this->planetService->getPlanetId())
        ->and($planet['units'][(string) ObjectService::getObjectByMachineName('light_fighter')->id])->toBe(7)
        ->and($planet['class_allowed'])->toEqualCanonicalizing(array_map(static fn (UnitObject $u): int => $u->id, $allowed));
});

test('exporting leaves the planet and its owner as it found them', function (): void {
    $planet = $this->planetService;
    $planet->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 4, false);
    $planet->addUnit('rocket_launcher', 3, false);
    $snapshot = static fn (): array => [
        array_map(static fn (GameObject $o): int => $planet->getObjectLevel($o->machine_name), [...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()]),
        array_map(static fn (GameObject $o): int => $planet->getPlayer()->getResearchLevel($o->machine_name), ObjectService::getResearchObjects()),
        array_map(static fn (UnitObject $u): int => $planet->getObjectAmount($u->machine_name), ObjectService::getUnitObjects()),
        [$planet->metal()->get(), $planet->crystal()->get(), $planet->deuterium()->get()],
    ];
    $before = $snapshot();

    app(ExportAiUnitsCatalogueAction::class)->handle([$planet], 2);

    expect($snapshot())->toBe($before);
});

test('the replay places orders through the host queue and reports what it queued and handed over', function (): void {
    DB::beginTransaction();
    $replay = app(ReplayAiUnitOrdersAction::class)->handle($this->planetService, 7, 40, 6 * 3600, 10);
    DB::rollBack();
    $accepted = array_values(array_filter($replay['orders'], static fn (array $o): bool => $o['accepted'] > 0));

    expect($replay['orders'])->toHaveCount(40)
        ->and($accepted)->not->toBeEmpty();
    foreach ($accepted as $index => $order) {
        expect($order['accepted'])->toBeLessThanOrEqual($order['amount'])
            ->and($order['end'])->toBeGreaterThan($order['start'])
            ->and($order['start'])->toBe($index === 0 ? $order['at'] : max($order['at'], $accepted[$index - 1]['end']));
    }
    $last = end($replay['observations']);
    foreach ($accepted as $order) {
        expect($last['units'][(string) $order['id']])->toBeGreaterThanOrEqual($order['accepted']);
    }
});
