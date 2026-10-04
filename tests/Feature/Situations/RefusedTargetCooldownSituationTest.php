<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Symfony\Component\Yaml\Yaml;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// DISPATCH-ROOT-001. A dispatch the gate refused is not a mission, so nothing the planners read
// recorded it and the next login offered the same target again (measured 4 Oct 2026: one account
// tried one target eight times in six hours). These two stories are the login after the refusal and
// the one after the cooling has passed: told the planet is online, the account leaves it alone --
// and comes back to it once the window the behaviour data names has gone.

/** The cooling a refusal buys, read from the behaviour data rather than restated here. */
function refusedTargetCooling(): int
{
    return (int) Yaml::parseFile(dirname(__DIR__, 3) . '/resources/behavior/dispatch-refusals.yaml')['cooling_minutes'];
}

/** The launch-guard neighbour: a playing owner holding a fleet, so the gate refuses the raid as too fresh. */
function refusedTargetSituation(object $test): Situation
{
    return Situation::of($test)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 50)
        ->ships('cruiser', 20)
        ->colony()
        ->inactiveNeighbour(daysQuiet: 0, metal: 400_000, crystal: 200_000)
        ->neighbourUnits('light_fighter', 5)
        ->spyReport();
}

/** How many raid intents the account has written: one more is the same target offered again. */
function refusedTargetRaids(Situation $situation): int
{
    return count(array_filter($situation->work(), static fn (AiWorkKind $kind): bool => $kind === AiWorkKind::Raid));
}

test('a target the gate refused as too fresh is not offered again on the next login', function (): void {
    $situation = refusedTargetSituation($this)->session();

    expect(implode(' ', $situation->refused()))->toContain('target_active_at_dispatch');
    $attempts = refusedTargetRaids($situation);

    $situation->minutesLater(45)->session();

    expect(refusedTargetRaids($situation))->toBe($attempts, 'the refused target was offered again inside the cooling')
        ->and($situation->missions())->not->toContain('Attack');
});

test('the same target is offered again once the refusal has cooled', function (): void {
    $situation = refusedTargetSituation($this);
    $neighbour = $situation->neighbour();
    $at = $neighbour->getPlanetCoordinates();

    $situation
        ->refusedDispatch($neighbour->getPlanetId(), $at->galaxy, $at->system, $at->position, 'target_active_at_dispatch', refusedTargetCooling() + 1)
        ->session()
        ->expectWork(AiWorkKind::Raid);
});
