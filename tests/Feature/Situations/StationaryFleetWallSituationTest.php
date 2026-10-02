<?php

use Modules\AI\Domain\Decision\DefenseNeed;
use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Tests\Support\Situation;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// PERS-004: what a planet stands to lose is not only the output that piles up while the account is
// away. A fleet left standing in orbit and the solar satellites a planet keeps for power are worth to
// a raider exactly what the pile is, so a wall sized on production alone leaves them uncovered. The
// kit plants the idle fleet on a planet whose small wall already covers the mines, and the evaluator
// must state a need only once the fleet is there.

test('an idle fleet raises what the planet stands to lose', function (): void {
    $situation = Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->level('shipyard', 4)
        ->ships('rocket_launcher', 200);

    $player = $this->planetService->getPlayer();
    $covered = app(DefenseNeedEvaluator::class)->evaluate($player, $this->planetService);

    expect($covered)->toBeNull('the wall already covers production-only exposure; ' . $situation->account());

    $situation->ships('light_fighter', 2_000);

    $exposed = app(DefenseNeedEvaluator::class)->evaluate($player, $this->planetService);

    expect($exposed)->toBeInstanceOf(DefenseNeed::class)
        ->and($exposed->protectedValue)->toBeGreaterThan(0.0)
        ->and($exposed->currentDefenseValue)->toBeGreaterThan(0.0);
});

// The zero case of the rule: the fleet only adds to the exposure, it is not what creates the need. A
// planet whose own output is nothing still takes the file's floor, so an account is not left naked
// because it has no fleet to protect.
test('a planet with nothing standing in orbit still takes the doctrine floor', function (): void {
    resolve(SettingsService::class)->set('economy_speed', 1);

    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->level('shipyard', 4)
        ->level('metal_mine', 0)
        ->level('crystal_mine', 0)
        ->level('deuterium_synthesizer', 0);

    $this->planetService->updateResourceProductionStats();

    $need = app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService);

    expect($need)->toBeInstanceOf(DefenseNeed::class)
        ->and($need->reason)->toBe('defense:need:minimum-deterrent');
});

// The bound: a fleet standing on a planet that already holds the wall the behaviour file caps adds
// nothing, because the ceiling ends the need before exposure is weighed at all.
test('a fleet on a planet at the wall ceiling wants no further wall', function (): void {
    Situation::of($this)
        ->resources(50_000_000, 50_000_000, 50_000_000)
        ->ships('light_fighter', 2_000)
        ->ships('rocket_launcher', 20_000);

    expect(app(DefenseNeedEvaluator::class)->evaluate($this->planetService->getPlayer(), $this->planetService))->toBeNull();
});
