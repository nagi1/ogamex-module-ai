<?php

use Modules\AI\Enums\GamePhase;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * ARCH-PHASE (architecture step 4, remainder): one phase machine every manager reads.
 *
 * Today the phase lives in a private method inside `RaidPlanner` and only gates raid targets; the
 * economy, research, fleet and defence managers have no phase at all. This is the red spec for
 * promoting it to a shared `GamePhaseMachine` and making the doctrine/managers phase-aware.
 *
 * RED until the class exists: the two tests below fail with "class not found".
 */
test('one phase machine derives the account phase from host state', function (): void {
    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $machine = app(\Modules\AI\Domain\Login\GamePhaseMachine::class);

    expect($machine->of($player))->toBe(GamePhase::Early);
});

test('a colony moves the account out of the opening phase', function (): void {
    Planet::factory()->create([
        'user_id' => $this->currentUserId,
        'galaxy' => 3,
        'system' => 123,
        'planet' => 4,
        'time_last_update' => now()->timestamp,
    ]);

    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $machine = app(\Modules\AI\Domain\Login\GamePhaseMachine::class);

    expect($machine->of($player))->toBe(GamePhase::Mid);
});
