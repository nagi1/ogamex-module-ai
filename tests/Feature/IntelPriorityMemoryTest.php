<?php

use Modules\AI\Actions\RecordObservedBattleReportAction;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Intel\IntelBook;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\BattleReport;
use OGame\Models\Planet;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// ARCH-INTEL on the account's own path: the counter is only worth keeping if a raid coming home writes it
// and the scout reads it back when it picks the next probe. A profitable raid goes in through the reducer
// the host's observer calls, and the scout's choice is read off the plan it returns, never off the book.
// The native affect engine answers here, so no driver or sidecar is involved, exactly as in the battle suite.
beforeEach(function (): void {
    config(['ai.cognition.driver' => 'native']);
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
});

/** The "g:s:p" a spy plan is aimed at, so a story can name the planet it expects to be read. */
function intelTargetKey(QueueableSpy $plan): string
{
    return $plan->targetGalaxy . ':' . $plan->targetSystem . ':' . $plan->targetPosition;
}

test('a target that paid raises the account priority and one that came home empty lowers it', function (): void {
    $profile = intelProfile($this->currentUserId);
    $defender = $this->createUser();

    // The host writes `loot` on every committed attack: an amount for a raid that brought resources home, a
    // row of zeros for one that did not. The reducer the battle observer calls reads exactly that column.
    $won = intelBattleReport($defender->id, $profile->player_id, 4, 200, 7, ['metal' => 5_000, 'crystal' => 2_000, 'deuterium' => 0]);
    app(RecordObservedBattleReportAction::class)->handle($won->id);

    $book = app(IntelBook::class);
    expect($book->priority($this->currentUserId, '4:200:7'))->toBe(1);

    $empty = intelBattleReport($defender->id, $profile->player_id, 4, 200, 7, ['metal' => 0, 'crystal' => 0, 'deuterium' => 0]);
    app(RecordObservedBattleReportAction::class)->handle($empty->id);

    expect($book->priority($this->currentUserId, '4:200:7'))->toBe(0);
});

test('the scout reads again the equal neighbour that paid, and drops it once it comes home empty', function (): void {
    Situation::of($this)->resources(0, 0, 100_000)->ships('espionage_probe', 1);
    $home = $this->planetService->getPlanetCoordinates();
    $left = intelPlaceFreighter($this->createForeignPlanet(), $home->galaxy, $home->system - 1);
    $right = intelPlaceFreighter($this->createForeignPlanet(), $home->galaxy, $home->system + 1);

    $planner = app(QueueableSpyPlanner::class);
    $first = $planner->plan($this->currentUserId);
    expect($first)->not->toBeNull();

    // Whichever of the two equal neighbours the scout was going to read anyway is the baseline; the one it
    // passed over is the one that then pays, so a scout that remembers its own raids comes back to it.
    $chosen = intelTargetKey($first);
    $paid = $chosen === $left ? $right : $left;
    app(IntelBook::class)->recordOutcome($this->currentUserId, $paid, true);

    $afterWin = $planner->plan($this->currentUserId);
    expect($afterWin)->not->toBeNull()
        ->and(intelTargetKey($afterWin))->toBe($paid);

    // The same two neighbours again, this time with the counter pushed back below zero: what stopped paying
    // gives way to the body beside it, which is the whole point of remembering a loss.
    app(IntelBook::class)->recordOutcome($this->currentUserId, $paid, false);
    app(IntelBook::class)->recordOutcome($this->currentUserId, $paid, false);

    $afterLoss = $planner->plan($this->currentUserId);
    expect($afterLoss)->not->toBeNull()
        ->and(intelTargetKey($afterLoss))->toBe($chosen);
});

/** A battle report the host would store, without firing the observer, so the test drives the reducer itself. */
function intelBattleReport(int $defenderId, int $attackerId, int $galaxy, int $system, int $position, array $loot): BattleReport
{
    return BattleReport::withoutEvents(fn (): BattleReport => BattleReport::unguarded(fn (): BattleReport => BattleReport::create([
        'planet_galaxy' => $galaxy,
        'planet_system' => $system,
        'planet_position' => $position,
        'planet_user_id' => $defenderId,
        'attacker' => ['player_id' => $attackerId],
        'defender' => ['player_id' => $defenderId],
        'loot' => $loot,
    ])));
}

function intelProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Raider,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

/**
 * Move a planted neighbour to one system either side of the account's home, at the same position: two
 * candidates the scout can only tell apart by what it remembers, never by distance or what a report showed.
 */
function intelPlaceFreighter(PlanetService $planet, int $galaxy, int $system): string
{
    Planet::query()->whereKey($planet->getPlanetId())->update(['galaxy' => $galaxy, 'system' => $system, 'planet' => 8]);

    return $galaxy . ':' . $system . ':8';
}
