<?php

use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Tests\Support\RaidStory;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';
require_once __DIR__ . '/../../Support/RaidStory.php';

uses(IsolatedAccountTestCase::class);

// War world (WORK-ORDER §2, PHP 2): every planet and moon is a target of its own. A farm's moon holding stock is raided
// beside its planet in the same login, and the bashing limit (six attacks per body in 24 h) closes one body, never the
// other one at the same coordinates.

/**
 * A raider holding a war fleet, and a farm whose planet and moon both hold stock, each with the account's own report.
 * $unseen changes both bodies after the reports, where the account cannot see it.
 *
 * @return array{0: Situation, 1: PlanetService, 2: PlanetService}
 */
function everyBodyStory(object $test, ?Closure $unseen = null): array
{
    $situation = Situation::of($test)
        ->archetype(AiArchetype::Raider)
        ->research('combustion_drive', 8)
        ->research('computer_technology', 6)
        ->ships('large_cargo', 100)
        ->ships('light_fighter', 100)
        ->resources(500_000, 500_000, 1_000_000)
        ->colony();
    $planet = RaidStory::farm($test);
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($planet, 2_000_000, 20);
    $moon->addResources(new Resources(200_000, 100_000, 0));
    RaidStory::quiet($planet, 10);
    $moon->reloadPlanet();
    RaidStory::report($test, $planet);
    RaidStory::report($test, $moon);
    if ($unseen !== null) {
        $unseen($planet);
        $unseen($moon);
    }

    return [$situation, $planet, $moon];
}

/** @return list<int> the body ids the account's attacks flew at, sorted */
function everyBodyAttacked(int $playerId): array
{
    $ids = FleetMission::query()->where('user_id', $playerId)->where('mission_type', 1)->where('processed', 0)->whereNull('parent_id')
        ->pluck('planet_id_to')->map(static fn ($id): int => (int) $id)->sort()->values()->all();

    return $ids;
}

test('a farm\'s moon holding stock is raided beside its planet in the same login', function (): void {
    [$situation, $planet, $moon] = everyBodyStory($this);
    $situation->session();

    expect($moon->getPlanetType())->toBe(PlanetType::Moon)
        ->and(everyBodyAttacked($this->currentUserId))->toBe(collect([$planet->getPlanetId(), $moon->getPlanetId()])->sort()->values()->all(), $situation->account());
});

test('a planet hit six times today is left alone while its moon is still raided', function (): void {
    [$situation, $planet, $moon] = everyBodyStory($this);
    RaidStory::attacked($this->currentUserId, $this->currentPlanetId, $planet->getPlanetId(), RaidPlanner::BASHING_LIMIT, hoursAgo: 10);
    $situation->session();

    expect(everyBodyAttacked($this->currentUserId))->toBe([$moon->getPlanetId()], $situation->account());
});

test('a moon hit six times today is left alone while its planet is still raided', function (): void {
    [$situation, $planet, $moon] = everyBodyStory($this);
    RaidStory::attacked($this->currentUserId, $this->currentPlanetId, $moon->getPlanetId(), RaidPlanner::BASHING_LIMIT, hoursAgo: 10);
    $situation->session();

    expect(everyBodyAttacked($this->currentUserId))->toBe([$planet->getPlanetId()], $situation->account());
});

test('six attacks older than a day no longer close the body', function (): void {
    [$situation, $planet, $moon] = everyBodyStory($this);
    RaidStory::attacked($this->currentUserId, $this->currentPlanetId, $planet->getPlanetId(), RaidPlanner::BASHING_LIMIT, hoursAgo: 26);
    $situation->session();

    expect(everyBodyAttacked($this->currentUserId))->toBe(collect([$planet->getPlanetId(), $moon->getPlanetId()])->sort()->values()->all(), $situation->account());
});

// No cheating (rule 10): stock carried off both bodies after the reports is not the account's to know.
test('both bodies emptied after their reports, unseen, are raided all the same', function (): void {
    [$situation, $planet, $moon] = everyBodyStory($this, static function (PlanetService $body): void {
        $body->deductResources($body->getResources());
    });
    $situation->session();

    expect(everyBodyAttacked($this->currentUserId))->toBe(collect([$planet->getPlanetId(), $moon->getPlanetId()])->sort()->values()->all(), $situation->account());
});
