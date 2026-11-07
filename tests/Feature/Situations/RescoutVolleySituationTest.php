<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Tests\Support\RaidStory;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\Planet;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';
require_once __DIR__ . '/../../Support/RaidStory.php';

uses(IsolatedAccountTestCase::class);

// War world (WORK-ORDER §3, PHP 6): re-scout. A fresh report the host redacted ("not enough probes") on a body worth it,
// rich or defended, is not settled intel: the next login sends the next larger volley at once instead of waiting out the
// intel window. A redacted report on an empty, unarmed body is left alone.

/** A settled raider holding a stack of probes. */
function rescoutRaider(object $test): Situation
{
    return Situation::of($test)
        ->archetype(AiArchetype::Raider)
        ->research('espionage_technology', 2)
        ->research('combustion_drive', 6)
        ->ships('espionage_probe', 40)
        ->ships('small_cargo', 1)
        ->resources(200_000, 200_000, 200_000)
        ->colony();
}

/** A farm beside the account's home: $metal stock and $fighters light fighters standing. */
function rescoutFarm(object $test, int $metal, int $fighters = 0): PlanetService
{
    $farm = RaidStory::farm($test, metal: $metal, crystal: 0);
    $home = Planet::query()->findOrFail((fn (): int => $this->currentPlanetId)->call($test));
    $taken = Planet::query()->where('galaxy', $home->galaxy)->where('system', $home->system)->pluck('planet')->all();
    $free = collect(range(4, 12))->first(static fn (int $position): bool => !in_array($position, $taken, true));
    Planet::query()->whereKey($farm->getPlanetId())->update(['galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $free]);
    $farm->reloadPlanet();
    $fighters > 0 && $farm->addUnit('light_fighter', $fighters);

    return $farm;
}

/** @return array<string, int> per target "g:s:p", the probes the account's spy orders send there */
function rescoutVolleys(int $playerId): array
{
    $volleys = [];
    foreach (AiWorkItem::query()->where('player_id', $playerId)->where('kind', AiWorkKind::Spy)->get(['payload']) as $item) {
        $p = $item->payload ?? [];
        $volleys[$p['target_galaxy'] . ':' . $p['target_system'] . ':' . $p['target_position']] = (int) ($p['probe_count'] ?? 0);
    }

    return $volleys;
}

function rescoutKey(PlanetService $planet): string
{
    $at = $planet->getPlanetCoordinates();

    return $at->galaxy . ':' . $at->system . ':' . $at->position;
}

test('a fresh redacted report on a rich farm is re-scouted with a larger volley', function (): void {
    $situation = rescoutRaider($this);
    $farm = rescoutFarm($this, metal: 2_000_000);
    RaidStory::report($this, $farm, hideShips: true);

    $situation->session();

    expect(rescoutVolleys($this->currentUserId)[rescoutKey($farm)] ?? null)->toBe(10, $situation->account());
});

test('a fresh report that read the fleet but not the defence is re-scouted with a larger volley', function (): void {
    $situation = rescoutRaider($this);
    $farm = rescoutFarm($this, metal: 0, fighters: 20);
    RaidStory::report($this, $farm, hideDefence: true);

    $situation->session();

    expect(rescoutVolleys($this->currentUserId)[rescoutKey($farm)] ?? null)->toBe(10, $situation->account());
});

test('the volley doubles again after a second redacted report', function (): void {
    $situation = rescoutRaider($this);
    $farm = rescoutFarm($this, metal: 2_000_000);
    RaidStory::report($this, $farm, hideShips: true);
    RaidStory::report($this, $farm, hideShips: true);

    $situation->session();

    expect(rescoutVolleys($this->currentUserId)[rescoutKey($farm)] ?? null)->toBe(20, $situation->account());
});

test('a fresh redacted report on an empty unarmed body is not re-scouted', function (): void {
    $situation = rescoutRaider($this);
    $farm = rescoutFarm($this, metal: 0);
    RaidStory::report($this, $farm, hideShips: true);

    $situation->session();

    expect(rescoutVolleys($this->currentUserId))->not->toHaveKey(rescoutKey($farm));
});

// No cheating (rule 10): the target's espionage technology and what it parked since are exactly what the account cannot
// read; the volley follows its own reports only.
test('the farm\'s hidden espionage technology and fleet leave the volley unchanged', function (): void {
    $situation = rescoutRaider($this);
    $farm = rescoutFarm($this, metal: 2_000_000);
    RaidStory::report($this, $farm, hideShips: true);
    $farm->getPlayer()->setResearchLevel('espionage_technology', 20);
    $farm->addUnit('battle_ship', 300);

    $situation->session();

    expect(rescoutVolleys($this->currentUserId)[rescoutKey($farm)] ?? null)->toBe(10, $situation->account());
});
