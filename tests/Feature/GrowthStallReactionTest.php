<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Symfony\Component\Yaml\Yaml;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

/**
 * Every planet mines deeper than the stock planted here can pay for, with the energy and storage that
 * keep the earlier passes out of the way: each planet is saving for a step out of its reach.
 */
function growthStallPlanet(Situation $situation): Situation
{
    return $situation
        ->levelEveryPlanet('metal_mine', 13)
        ->levelEveryPlanet('crystal_mine', 13)
        ->levelEveryPlanet('deuterium_synthesizer', 13)
        ->levelEveryPlanet('solar_plant', 30)
        ->levelEveryPlanet('metal_store', 20)
        ->levelEveryPlanet('crystal_store', 20)
        ->levelEveryPlanet('deuterium_store', 20)
        ->stockEveryPlanet(5_000, 2_000, 1_000);
}

/** The window the behaviour file states, so the stories below test the bound the module ships with. */
function growthStallBound(): int
{
    return (int) Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/growth.yaml')['stalled_growth']['flat_hours'];
}

/** A history that has not moved: the window's worth of hourly samples carrying one score. */
function growthStallFlatHistory(int $hours): array
{
    return array_fill(0, $hours, 100);
}

// IMPL-69: the growth loop is closed against the account's own hourly score. A goal saver whose score
// has not moved for the whole window is not saving toward a step it is reaching -- it is stuck behind
// one -- so it spends the pile it was holding, which is what a player does whose points have been flat
// all day. Before this reaction the saver queued nothing at all: see SavingsGoalSituationTest, which
// plants the same planet with no score history and still holds.
test('a goal saver whose score has not moved for the window spends the pile it was holding', function (): void {
    growthStallPlanet(Situation::of($this)->goalSaver())
        ->scoreHistory(growthStallFlatHistory(growthStallBound()))
        ->session()
        ->expectWork(AiWorkKind::BuildFirstBuilding)
        ->expectBuildingQueueBusy();
});

// At the bound minus one the account is still saving: the step it is saving for may still be arriving,
// so the pile stays where it is and no build is ordered.
test('a goal saver one sample short of the window keeps holding its pile', function (): void {
    $situation = growthStallPlanet(Situation::of($this)->goalSaver())
        ->scoreHistory(growthStallFlatHistory(growthStallBound() - 1))
        ->session();

    expect($situation->queued())->toBeEmpty('expected the saving to hold, but ' . $situation->account())
        ->and($situation->work())->not->toContain(AiWorkKind::BuildFirstBuilding);
});

// With no score history at all the account is an account nobody has watched: a young one is not
// stalled, and the saving stands.
test('a goal saver with no score history keeps holding its pile', function (): void {
    $situation = growthStallPlanet(Situation::of($this)->goalSaver())->session();

    expect($situation->queued())->toBeEmpty('expected the saving to hold, but ' . $situation->account())
        ->and($situation->work())->not->toContain(AiWorkKind::BuildFirstBuilding);
});

// A score that moved inside the window is growth, however little: the account is not stalled, so the
// reaction is a stall reaction and not a ban on saving.
test('a goal saver whose score moved inside the window keeps holding its pile', function (): void {
    $moved = array_merge([101], growthStallFlatHistory(growthStallBound() - 1));

    $situation = growthStallPlanet(Situation::of($this)->goalSaver())
        ->scoreHistory($moved)
        ->session();

    expect($situation->queued())->toBeEmpty('expected the saving to hold, but ' . $situation->account())
        ->and($situation->work())->not->toContain(AiWorkKind::BuildFirstBuilding);
});
