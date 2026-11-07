<?php

use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Tests\Support\RaidStory;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';
require_once __DIR__ . '/../../Support/RaidStory.php';

uses(IsolatedAccountTestCase::class);

// War world (WORK-ORDER §3, PHP 4): target selection follows the live servers' newbie bands (host
// PlayerService::protectsNewbie): under 50,000 points a player is protected from anyone 5 times stronger, up to 500,000
// from anyone 10 times, above that from nobody. The galaxy view shows the mark, so a raider neither scouts nor raids a
// protected body, and scouts and raids a small player above the limit however far below its own points.

/** A raider on $points public points with probes, a fleet and fuel. */
function newbieBandsRaider(object $test, int $points): Situation
{
    $situation = Situation::of($test)
        ->archetype(AiArchetype::Raider)
        ->research('espionage_technology', 4)
        ->research('combustion_drive', 8)
        ->ships('espionage_probe', 30)
        ->ships('large_cargo', 50)
        ->ships('light_fighter', 50)
        ->resources(500_000, 500_000, 1_000_000)
        ->colony();
    Highscore::query()->updateOrCreate(['player_id' => (fn (): int => $this->currentUserId)->call($test)], ['general' => $points]);

    return $situation;
}

/**
 * A rival on $points public points beside the account's home, last seen $daysQuiet days ago (an active player away from
 * the keyboard by default: inactivity starts at seven), holding stock.
 */
function newbieBandsRival(object $test, int $points, int $daysQuiet = 2): PlanetService
{
    $rival = RaidStory::farm($test, $daysQuiet);
    $home = Planet::query()->findOrFail((fn (): int => $this->currentPlanetId)->call($test));
    $taken = Planet::query()->where('galaxy', $home->galaxy)->where('system', $home->system)->pluck('planet')->all();
    $free = collect(range(4, 12))->first(static fn (int $position): bool => !in_array($position, $taken, true));
    Planet::query()->whereKey($rival->getPlanetId())->update(['galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $free]);
    Highscore::query()->updateOrCreate(['player_id' => $rival->getPlayer()->getId()], ['general' => $points]);

    return app(PlanetServiceFactory::class)->make($rival->getPlanetId(), true);
}

/** @return list<int>|null the coordinates the account's next probe flies to */
function newbieBandsScouted(int $playerId): array|null
{
    $plan = app(QueueableSpyPlanner::class)->plan($playerId);

    return $plan instanceof QueueableSpy ? [$plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition] : null;
}

function newbieBandsAt(PlanetService $planet): array
{
    $at = $planet->getPlanetCoordinates();

    return [$at->galaxy, $at->system, $at->position];
}

test('an active player under a fifth of a small raider\'s points is not scouted', function (): void {
    newbieBandsRaider($this, 100_000);
    $newbie = newbieBandsRival($this, 15_000);

    expect(newbieBandsScouted($this->currentUserId))->not->toBe(newbieBandsAt($newbie));
});

test('an active player over a fifth of a small raider\'s points is scouted', function (): void {
    newbieBandsRaider($this, 100_000);
    $rival = newbieBandsRival($this, 25_000);

    expect(newbieBandsScouted($this->currentUserId))->toBe(newbieBandsAt($rival));
});

test('between 50,000 and 500,000 points a player under a tenth of the raider is not scouted', function (): void {
    newbieBandsRaider($this, 1_000_000);
    $protected = newbieBandsRival($this, 90_000);

    expect(newbieBandsScouted($this->currentUserId))->not->toBe(newbieBandsAt($protected));
});

test('between 50,000 and 500,000 points a player over a tenth of the raider is scouted', function (): void {
    newbieBandsRaider($this, 1_000_000);
    $rival = newbieBandsRival($this, 120_000);

    expect(newbieBandsScouted($this->currentUserId))->toBe(newbieBandsAt($rival));
});

test('a player above 500,000 points is scouted and raided however far below the raider', function (): void {
    $situation = newbieBandsRaider($this, 50_000_000);
    $rival = newbieBandsRival($this, 600_000);

    expect(newbieBandsScouted($this->currentUserId))->toBe(newbieBandsAt($rival));

    RaidStory::report($this, $rival);
    $situation->session()->expectMission('Attack');
});

test('a protected newbie\'s report is not raided', function (): void {
    $situation = newbieBandsRaider($this, 100_000);
    $newbie = newbieBandsRival($this, 15_000);
    RaidStory::report($this, $newbie);

    $situation->session()->expectNoMission('Attack');
});

test('an inactive small player carries no newbie mark and is scouted', function (): void {
    newbieBandsRaider($this, 100_000);
    $farm = newbieBandsRival($this, 5_000, daysQuiet: 10);

    expect(newbieBandsScouted($this->currentUserId))->toBe(newbieBandsAt($farm));
});

// No cheating (rule 10): the mark is read from public points; the newbie's stock and fleet, unseen, change nothing.
test('a protected newbie\'s hidden riches do not tempt the scout', function (): void {
    newbieBandsRaider($this, 100_000);
    $newbie = newbieBandsRival($this, 15_000);
    $newbie->addResources(new OGame\Models\Resources(50_000_000, 50_000_000, 50_000_000));
    $newbie->addUnit('battle_ship', 1_000);

    expect(newbieBandsScouted($this->currentUserId))->not->toBe(newbieBandsAt($newbie));
});
