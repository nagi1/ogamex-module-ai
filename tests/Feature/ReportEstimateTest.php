<?php

use Modules\AI\Domain\Raid\ReportedPlanet;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// A player raids on a redacted report by assuming the worst it can read: every public military point the report does
// not explain stands on the planet (rl/ogsim players::guessed_sections is the same rule).

test('a full report is not estimated', function (): void {
    $observer = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $report = reportEstimateReport($this->currentUserId, ['light_fighter' => 3], ['rocket_launcher' => 2]);

    expect(app(ReportedPlanet::class)->estimated($report, $observer))->toBe($report);
});

test('a hidden defence is the unexplained military points as the cheapest real defence', function (): void {
    $observer = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $owner = User::factory()->create();
    // 30 points of military: the 3 light fighters shown explain 12 of them.
    reportEstimatePoints($owner->id, 30);
    $report = reportEstimateReport($this->currentUserId, ['light_fighter' => 3], null, $owner->id);

    $estimate = app(ReportedPlanet::class)->estimated($report, $observer);
    $cheapest = collect(ObjectService::getDefenseObjects())
        ->filter(fn ($unit): bool => $unit->properties->attack->calculate($observer)->totalValue > 1)
        ->sortBy(fn ($unit): float => ObjectService::getObjectRawPrice($unit->machine_name)->sum())
        ->first();

    expect($estimate->ships)->toBe(['light_fighter' => 3])
        ->and($estimate->defense)->toBe([$cheapest->machine_name => (int) ceil(18_000 / ObjectService::getObjectRawPrice($cheapest->machine_name)->sum())])
        ->and($estimate->exists)->toBeFalse();
});

test('a hidden defence takes the mix the account last read on that planet', function (): void {
    $observer = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $owner = User::factory()->create();
    reportEstimatePoints($owner->id, 40);
    reportEstimateReport($this->currentUserId, [], ['light_laser' => 1], $owner->id);
    $report = reportEstimateReport($this->currentUserId, [], null, $owner->id);

    // 40 000 unexplained over a light laser's 2 000: twenty of them.
    expect(app(ReportedPlanet::class)->estimated($report, $observer)->defense)->toBe(['light_laser' => 20]);
});

test('another player\'s older report is not the account\'s to read', function (): void {
    $observer = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    reportEstimatePoints($owner->id, 40);
    reportEstimateReport($other->id, [], ['light_laser' => 1], $owner->id);
    $report = reportEstimateReport($this->currentUserId, [], null, $owner->id);

    expect(app(ReportedPlanet::class)->estimated($report, $observer)->defense)->not->toHaveKey('light_laser');
});

test('no unexplained military points leave the hidden section empty', function (): void {
    $observer = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $owner = User::factory()->create();
    reportEstimatePoints($owner->id, 12);
    $report = reportEstimateReport($this->currentUserId, ['light_fighter' => 3], null, $owner->id);

    expect(array_sum(app(ReportedPlanet::class)->estimated($report, $observer)->defense))->toBe(0);
});

function reportEstimatePoints(int $playerId, int $military): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(['player_id' => $playerId], ['military_built' => $military]));
}

/**
 * @param array<string, int>|null $ships
 * @param array<string, int>|null $defense
 */
function reportEstimateReport(int $readerId, array|null $ships, array|null $defense, int|null $ownerId = null): EspionageReport
{
    $report = new EspionageReport();
    $report->planet_galaxy = 3;
    $report->planet_system = 33;
    $report->planet_position = 7;
    $report->planet_type = 1;
    $report->planet_user_id = $ownerId;
    $report->resources = ['metal' => 50_000, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = $ships;
    $report->defense = $defense;
    $report->player_info = [];
    $report->save();

    $reader = app(PlayerServiceFactory::class)->make($readerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($reader, $report->id);

    return $report;
}
