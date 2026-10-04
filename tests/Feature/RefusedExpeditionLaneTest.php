<?php

use Modules\AI\Domain\Decision\QueueableExpedition;
use Modules\AI\Domain\Decision\QueueableExpeditionPlanner;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// DISPATCH_REFUSALS: the expedition slot is the account's, not one body's. The host's own words for it
// ("You are conducting too many expeditions at the same time.") name no target and say nothing about the
// body the fleet would leave from, so an origin-only memory sent the next login to a sibling and got the
// same answer — measured 4 Oct 2026: one body refused for this five times in six hours. A player who is
// told the account is out of expedition slots waits for one to come home.

test('a refusal of the account own dispatch orders waits for a slot instead of sending from a sibling', function (): void {
    $situation = Situation::of($this)
        ->colony()
        ->research('astrophysics', 4)
        ->research('combustion_drive', 6)
        ->shipsEveryPlanet('large_cargo', 10)
        ->stockEveryPlanet(0, 0, 500_000);

    // Before the refusal both bodies are candidates, so the offered expedition exists to be refused.
    expect(app(QueueableExpeditionPlanner::class)->plan($this->currentUserId))->toBeInstanceOf(QueueableExpedition::class);

    // The host's refusal for this lane names no target and only the body it happened to leave from.
    $situation->refusedDispatch($this->currentPlanetId, reason: 'You are conducting too many expeditions at the same time.');

    // The body memory alone would fly the sibling; the account's slot is what the gate refused.
    expect(app(QueueableExpeditionPlanner::class)->plan($this->currentUserId))->toBeNull();

    $situation->sessions(4)->expectNoWork(AiWorkKind::Expedition);
});

// At zero: the same fleet with no refusal on record takes the errand, so the plant above is what stops it.
test('the same account flies the expedition when the gate refused nothing', function (): void {
    Situation::of($this)
        ->research('astrophysics', 3)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 10)
        ->resources(500_000, 500_000, 500_000)
        ->sessions(4)
        ->expectWork(AiWorkKind::Expedition);
});

// Past the bound: a refusal a night old is forgotten, as a player who has waited for the slot tries again.
test('a refusal outside the cooling no longer stops the expedition', function (): void {
    Situation::of($this)
        ->research('astrophysics', 3)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 10)
        ->resources(500_000, 500_000, 500_000)
        ->refusedDispatch($this->currentPlanetId, reason: 'You are conducting too many expeditions at the same time.', minutesAgo: 720)
        ->sessions(4)
        ->expectWork(AiWorkKind::Expedition);
});
