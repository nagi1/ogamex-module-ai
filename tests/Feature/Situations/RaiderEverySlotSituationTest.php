<?php

use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\MessageService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// War world (WORK-ORDER §2, PHP 1): a raider uses every fleet slot the host grants it, not a fixed four. With more
// fresh reports on farms than slots, one login sends a raid through every free slot but the one kept for a save.

/** An inactive farm with stock and the account's own full report on it, as the host's espionage mission writes one. */
function everySlotFarm(object $test): PlanetService
{
    $farm = (fn (): PlanetService => $this->createForeignPlanet())->call($test);
    $farm->addResources(new Resources(300_000, 150_000, 0));
    DB::table('users')->where('id', $farm->getPlayer()->getId())->update(['time' => now()->subDays(10)->timestamp]);
    Planet::query()->whereKey($farm->getPlanetId())->update(['time_last_update' => now()->subDays(10)->timestamp]);
    $farm->reloadPlanet();

    $at = $farm->getPlanetCoordinates();
    $report = new EspionageReport();
    $report->planet_galaxy = $at->galaxy;
    $report->planet_system = $at->system;
    $report->planet_position = $at->position;
    $report->planet_type = $farm->getPlanetType()->value;
    $report->planet_user_id = $farm->getPlayer()->getId();
    $report->player_info = ['player_id' => (string) $farm->getPlayer()->getId(), 'player_name' => $farm->getPlayer()->getUsername()];
    $report->resources = ['metal' => (int) $farm->metal()->get(), 'crystal' => (int) $farm->crystal()->get(), 'deuterium' => 0, 'energy' => 0];
    $report->ships = [];
    $report->defense = [];
    $report->buildings = $farm->getBuildingArray();
    $report->research = $farm->getPlayer()->getResearchArray();
    $report->counter_espionage_chance = 0;
    $report->save();
    app(MessageService::class)->sendEspionageReportMessageToPlayer((fn (): PlanetService => $this->planetService)->call($test)->getPlayer(), $report->id);

    return $farm;
}

/**
 * A settled raider holding cargo and kill ships for many waves, with $farms fresh reports and computer technology
 * $computer. $unseen changes each farm after its report, where the account cannot see it.
 */
function everySlotRaider(object $test, int $computer, int $farms, ?Closure $unseen = null): Situation
{
    $situation = Situation::of($test)
        ->archetype(AiArchetype::Raider)
        ->research('combustion_drive', 8)
        ->research('computer_technology', $computer)
        ->ships('large_cargo', 200)
        ->ships('light_fighter', 200)
        ->resources(500_000, 500_000, 2_000_000)
        ->colony();
    for ($farm = 0; $farm < $farms; $farm++) {
        $planet = everySlotFarm($test);
        $unseen !== null && $unseen($planet);
    }

    return $situation;
}

test('a raider with more than four slots raids through every free slot but the one it keeps', function (): void {
    $situation = everySlotRaider($this, computer: 6, farms: 9)->session();

    $slots = app(PlayerServiceFactory::class)->make($this->currentUserId, true)->getFleetSlotsMax();
    $attacks = count(array_filter($situation->missions(), static fn (string $mission): bool => $mission === 'Attack'));

    expect($slots)->toBeGreaterThan(5)
        ->and($attacks)->toBe($slots - 1, 'attacks ' . $attacks . ' of ' . $slots . ' slots; ' . $situation->account());
});

// A late raider holds a dozen slots: its personality's wave count must not cap it below what the host grants.
test('a raider with a dozen slots fills them all in one login', function (): void {
    $situation = everySlotRaider($this, computer: 11, farms: 14)->session();

    $slots = app(PlayerServiceFactory::class)->make($this->currentUserId, true)->getFleetSlotsMax();
    $attacks = count(array_filter($situation->missions(), static fn (string $mission): bool => $mission === 'Attack'));

    expect($slots)->toBeGreaterThan(10)
        ->and($attacks)->toBe($slots - 1, 'attacks ' . $attacks . ' of ' . $slots . ' slots; ' . $situation->account());
});

// No cheating (rule 10): what happened on the farms after the reports (stock carried off, a fleet parked) is not the
// account's to know, so the same raids leave through the same slots.
test('a farm changed after its report, unseen, leaves the raids through every slot unchanged', function (): void {
    $situation = everySlotRaider($this, computer: 6, farms: 9, unseen: static function (PlanetService $farm): void {
        $farm->deductResources($farm->getResources());
        $farm->addUnit('battle_ship', 5);
    })->session();

    $slots = app(PlayerServiceFactory::class)->make($this->currentUserId, true)->getFleetSlotsMax();
    $attacks = count(array_filter($situation->missions(), static fn (string $mission): bool => $mission === 'Attack'));

    expect($attacks)->toBe($slots - 1, 'attacks ' . $attacks . ' of ' . $slots . ' slots; ' . $situation->account());
});
