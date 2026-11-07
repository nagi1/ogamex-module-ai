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

// A step the planet's own mines bring within reach only weeks from now is not booked: such a booking (a colony saving for
// a late astrophysics level) held the planet's slot for good and wrote a due time past the timestamp column's 2038 end.
test('a login books no step its planet pays for only beyond a day from now', function (int $deuteriumPercent, int $temperature, bool $booked): void {
    $situation = Situation::of($this)
        ->colony()
        ->levelEveryPlanet('metal_mine', 12)
        ->levelEveryPlanet('crystal_mine', 12)
        ->levelEveryPlanet('deuterium_synthesizer', 12)
        ->levelEveryPlanet('solar_plant', 30)
        ->levelEveryPlanet('robot_factory', 10)
        ->levelEveryPlanet('research_lab', 10)
        ->levelEveryPlanet('shipyard', 8)
        ->research('energy_technology', 8)
        ->research('computer_technology', 8)
        ->drained()
        ->hostileFleet(900);
    // A throttled synthesizer on a scorching planet makes a trickle: the deuterium the next research needs is days away.
    Planet::query()->where('user_id', $this->currentUserId)->update(['deuterium_synthesizer_percent' => $deuteriumPercent, 'temp_min' => $temperature, 'temp_max' => $temperature]);

    $situation->session();

    $bookings = AiWorkItem::query()->where('player_id', $this->currentUserId)->get()
        ->filter(fn (AiWorkItem $item): bool => str_contains((string) $item->idempotency_key, ':saved:'));

    expect($bookings->isNotEmpty())->toBe($booked)
        ->and($bookings->filter(fn (AiWorkItem $item): bool => $item->due_at->greaterThan(now()->addDay())))->toBeEmpty();
})->with(['within a day' => [10, 20, true], 'days away' => [1, 355, false]]);
