<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// LIFE-002: a rich account whose yard is capable and whose habits are satisfied spends the session on the
// strongest military hull the host lets it build. The hull is read from the catalogue, never named: the
// account grows a war fleet as research unlocks bigger hulls, so large battles appear on the live cohorts.

test('a rich account with a capable yard grows its war fleet from the best hull the host offers', function (): void {
    // The hulls the catalogue prices above the median military hull: the war fleet the invariant reads.
    $military = collect(ObjectService::getMilitaryShipObjects())
        ->reject(fn ($hull): bool => $hull->machine_name === 'espionage_probe');
    $price = fn ($hull): float => (float) $hull->price->resources->sum();
    $prices = $military->map($price)->sort()->values();
    $median = $prices[intdiv($prices->count(), 2)];
    $capital = $military->filter(fn ($hull): bool => $price($hull) > $median)->values();

    $situation = Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->resources(50_000_000, 30_000_000, 20_000_000)
        ->ships('large_cargo', 5)
        ->ships('espionage_probe', 1)
        ->ships('colony_ship', 1)
        ->ships('light_fighter', 1)
        ->defence('rocket_launcher', 20_000);

    // The host's own requirement graph unlocks the cheapest capital hull, exactly as research and a yard
    // unlock it on a live account; nothing here names a building, a technology or a ship.
    $target = $capital->sortBy($price)->first();
    foreach (ObjectService::getRecursiveRequirements($target->machine_name) as $machineName => $level) {
        if (ObjectService::getObjectByMachineName($machineName)->type === GameObjectType::Research) {
            $situation->research($machineName, $level);
            continue;
        }

        $situation->level($machineName, $level);
    }

    $situation->session();

    expect($situation->work())->toContain(AiWorkKind::QueueUnits)
        ->and(array_intersect($capital->pluck('machine_name')->all(), $situation->queued()))->not->toBeEmpty();
});
