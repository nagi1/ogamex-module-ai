<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\DebrisField;
use OGame\Models\FleetMission;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// DISPATCH_REFUSALS: the cohort invariant counts a repeat of the same account + target (or origin) +
// reason inside six hours, so a cooling shorter than that span lets the same refusal be made again
// inside the window it measures (measured 4 Oct 2026: one target refused 21 times in six hours). The
// harvest beside the planet is the story: an ordinary session sends its recyclers, and the only thing
// that stops it is the refusal the gate made hours ago — a refusal is not a mission, so this memory is
// the only trace of it a planner reads.

test('a field the gate refused four hours ago is still not the harvest this session sends', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    $home = $this->planetService->getPlanetCoordinates();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('recycler', 2)
        ->debris(40_000, 20_000)
        ->refusedDispatch(galaxy: (int) $home->galaxy, system: (int) $home->system, position: (int) $home->position, reason: 'source_short_at_dispatch', minutesAgo: 240)
        ->session()
        ->expectNoWork(AiWorkKind::Recycle);
});

test('a body the gate refused four hours ago is not the origin this session flies from', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('recycler', 2)
        ->debris(40_000, 20_000)
        ->refusedDispatch($this->currentPlanetId, reason: 'no_disposable_fleet', minutesAgo: 240)
        ->session()
        ->expectNoWork(AiWorkKind::Recycle);
});

// Past the bound: the cooling a launched mission buys its target has run out, so the account flies again
// instead of sitting on a field for ever.
test('the same refusal cools off after the hours a launched mission would have cooled', function (): void {
    DebrisField::query()->delete();
    FleetMission::query()->delete();

    $home = $this->planetService->getPlanetCoordinates();

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('recycler', 2)
        ->debris(40_000, 20_000)
        ->refusedDispatch(galaxy: (int) $home->galaxy, system: (int) $home->system, position: (int) $home->position, reason: 'source_short_at_dispatch', minutesAgo: 420)
        ->session()
        ->expectWork(AiWorkKind::Recycle);
});
