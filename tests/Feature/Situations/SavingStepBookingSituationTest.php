<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ECON-001 (aspect: economy, invariant: IDLE_QUEUES) for the planet a login cannot pay for yet: at
// universe speed a step that is short of resources at one login is affordable minutes later, so a
// login that ordered nothing for it leaves a buildable planet idle until the next routine login. A
// player leaves the order with the account instead, and this is that order.
test('a login books the step an account cannot pay for yet, for the instant it can', function (): void {
    $situation = Situation::of($this)
        ->colony()
        ->levelEveryPlanet('metal_mine', 15)
        ->levelEveryPlanet('crystal_mine', 15)
        ->levelEveryPlanet('deuterium_synthesizer', 15)
        ->levelEveryPlanet('solar_plant', 45)
        ->levelEveryPlanet('metal_store', 10)
        ->levelEveryPlanet('crystal_store', 10)
        ->levelEveryPlanet('deuterium_store', 10)
        ->drained()
        // Only the logged-in planet is funded: the colony is the queue this login cannot fill.
        ->resources(5_000_000, 5_000_000, 5_000_000)
        // An inbound fleet keeps the login off the humaniser's idle draw, so the story is about the
        // economy and not about which action the persona happened to pick.
        ->hostileFleet(900);

    $colony = (int) Planet::query()->where('user_id', $this->currentUserId)->where('planet_type', 1)
        ->whereKeyNot($this->currentPlanetId)->value('id');

    $situation->session();

    $booking = AiWorkItem::query()->where('player_id', $this->currentUserId)->get()
        ->first(fn (AiWorkItem $item): bool => str_ends_with((string) $item->idempotency_key, ':saved:' . $colony));

    // The colony is the planet the login cannot pay for: its step waits on the account rather than the
    // login leaving the queue empty (which is what the cohort read-out then calls a buildable, idle
    // planet). The kit's session() runs due intents, so the booking may already have been placed.
    expect($booking)->not->toBeNull('expected the colony\'s step to be booked, but ' . $situation->account());

    expect((int) ($booking->payload['planet_id'] ?? 0))->toBe($colony)
        ->and($booking->kind)->toBeIn([AiWorkKind::BuildFirstBuilding, AiWorkKind::QueueResearch]);
});
