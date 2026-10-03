<?php

use Modules\AI\Domain\Decision\ThreatResponsePlanner;
use Modules\AI\Enums\AiThreatResponse;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// PERS-006: an inbound is not one order, and it is not one reactive turret either. A fleet too small for
// the save band stays home — moving it is not worth the trip — and the stock the raider would otherwise
// carry off leaves instead, on the hulls the account keeps. The same attack, a different set of intents,
// chosen by the threat planner rather than by the host's under-attack flag alone.

test('a fleet too small to save stays home while its stock leaves', function (): void {
    Situation::of($this)
        ->resources(400_000, 400_000, 100_000)
        ->ships('small_cargo', 5)
        ->hostileFleet(seconds: 150)
        ->session()
        ->expectWork(AiWorkKind::Transfer)
        ->expectMission('Transport')
        ->expectNoMission('Deployment');
});

// The arm a leaving fleet closes: the save takes the body's hulls with it, so there is nothing left at
// home to carry the pile with and the account answers the inbound with the save alone.
test('a fleet that is worth the trip leaves and takes the carriers with it', function (): void {
    $situation = Situation::of($this)
        ->resources(400_000, 400_000, 100_000)
        ->ships('large_cargo', 5)
        ->hostileFleet(seconds: 150);

    $plan = app(ThreatResponsePlanner::class)->plan($this->currentUserId);

    expect($plan->holds($this->currentPlanetId, AiThreatResponse::EvacuateFleet))->toBeTrue()
        ->and($plan->holds($this->currentPlanetId, AiThreatResponse::EvacuateResources))->toBeFalse()
        ->and($plan->holds($this->currentPlanetId, AiThreatResponse::AttemptNinja))->toBeFalse()
        ->and($situation->work())->toBe([]);
});

// The zero end: an account nobody is flying at has nothing to answer, so the planner reports no attack
// and names no response at all.
test('an account with no inbound answers nothing', function (): void {
    Situation::of($this)->resources(400_000, 400_000, 100_000)->ships('large_cargo', 5);

    $plan = app(ThreatResponsePlanner::class)->plan($this->currentUserId);

    expect($plan->underAttack)->toBeFalse()
        ->and($plan->responses)->toBe([]);
});
