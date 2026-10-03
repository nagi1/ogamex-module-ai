<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ECON-001's other half (aspect: economy, invariant: IDLE_QUEUES). A login that cannot yet pay for
// the next step is not a login that leaves every build queue empty until the routine one: a player
// comes back when the mine they are saving for is payable, and at universe speed that is minutes.
test('an account minutes short of its next step books its next login before the routine one', function (): void {
    Situation::of($this)
        ->levelEveryPlanet('metal_mine', 15)
        ->levelEveryPlanet('crystal_mine', 15)
        ->levelEveryPlanet('deuterium_synthesizer', 15)
        ->levelEveryPlanet('solar_plant', 45)
        ->levelEveryPlanet('metal_store', 10)
        ->levelEveryPlanet('crystal_store', 10)
        ->levelEveryPlanet('deuterium_store', 10)
        ->drained()
        ->session();

    expect(nextLoginMinutes($this->currentUserId))->toBeLessThanOrEqual(20);
});

// The bound's other side: an account that can pay for its next step right now has no shortfall to
// come back for, so it keeps its ordinary login.
test('an account that can already pay waits for its ordinary login', function (): void {
    Situation::of($this)
        ->levelEveryPlanet('metal_mine', 15)
        ->levelEveryPlanet('crystal_mine', 15)
        ->levelEveryPlanet('deuterium_synthesizer', 15)
        ->levelEveryPlanet('solar_plant', 45)
        ->levelEveryPlanet('metal_store', 10)
        ->levelEveryPlanet('crystal_store', 10)
        ->levelEveryPlanet('deuterium_store', 10)
        ->stockEveryPlanet(5_000_000, 5_000_000, 5_000_000)
        ->session();

    // The routine gap is tens of minutes: 20 is below it, so the two stories cannot both hold.
    expect(nextLoginMinutes($this->currentUserId))->toBeGreaterThan(20);
});

/** Minutes until the login the account has booked for itself. */
function nextLoginMinutes(int $playerId): float
{
    $dueAt = AiWorkItem::query()->where('player_id', $playerId)
        ->where('kind', AiWorkKind::RunSession)
        ->where('state', AiWorkState::Pending)
        ->orderByDesc('id')
        ->value('due_at');

    return ((int) strtotime((string) $dueAt) - now()->getTimestamp()) / 60;
}
