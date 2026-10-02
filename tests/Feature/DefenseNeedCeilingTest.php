<?php

use Modules\AI\Domain\Decision\DefenseCompositionPlanner;
use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Tests\Support\Situation;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// The wall's size has to stop somewhere. Exposure is production times the hours the account is
// away and has no bound of its own, so an account sized on it keeps buying and lands its whole
// defence budget on one planet (live 30 Sep 2026: 1,285,633 units on a single planet, 60+ over
// the 20,000 ceiling). The cap the behaviour file states is the stop; these stories prove the
// account builds its doctrine floor below it and nothing at or past it.

/** The cheapest host defence object, planted in bulk so a planet reaches the ceiling. */
function defenseCeilingBulkUnit(): string
{
    return 'rocket_launcher';
}

test('a planet with no wall is still offered its doctrine floor', function (): void {
    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->level('shipyard', 12);

    $wall = app(DefenseCompositionPlanner::class)->plan($this->planetService->getPlayer(), $this->planetService);

    expect($wall)->not->toBeNull('expected the doctrine floor at zero defence, but the planner composed nothing');
});

test('one unit below the ceiling still wants a wall, hostile or not', function (): void {
    Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->ships(defenseCeilingBulkUnit(), 19_999)
        ->hostileFleet(600);

    $need = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    expect($need)->not->toBeNull('expected a need just below the ceiling, but the evaluator stated none');
});

test('a planet at the ceiling is offered no further wall', function (): void {
    Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->ships(defenseCeilingBulkUnit(), 20_000)
        ->hostileFleet(600);

    $player = $this->planetService->getPlayer();

    expect(app(DefenseNeedEvaluator::class)->evaluate($player, $this->planetService))->toBeNull()
        ->and(app(DefenseCompositionPlanner::class)->plan($player, $this->planetService))->toBeNull();
});

test('a planet past the ceiling is offered no further wall', function (): void {
    Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->ships(defenseCeilingBulkUnit(), 20_001)
        ->hostileFleet(600);

    $player = $this->planetService->getPlayer();

    expect(app(DefenseNeedEvaluator::class)->evaluate($player, $this->planetService))->toBeNull()
        ->and(app(DefenseCompositionPlanner::class)->plan($player, $this->planetService))->toBeNull();
});

test('a session on a planet past the ceiling queues no further wall', function (): void {
    $defenceNames = array_map(
        static fn ($object): string => $object->machine_name,
        ObjectService::getDefenseObjects()
    );

    $situation = Situation::of($this)
        ->resources(5_000_000, 5_000_000, 5_000_000)
        ->ships(defenseCeilingBulkUnit(), 20_001)
        ->session();

    $queuedDefence = array_values(array_intersect($situation->queued(), $defenceNames));

    expect($queuedDefence)->toBe([], 'expected no defence in the queue, but ' . $situation->account());
});
