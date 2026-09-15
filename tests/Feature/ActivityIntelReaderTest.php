<?php

use Modules\AI\Domain\Perception\ActivityIntelReader;
use Modules\AI\Domain\Perception\PlayerObservationService;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Services\MessageService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('activity_at reads the fifteen-minute window from the host', function (): void {
    $reader = app(ActivityIntelReader::class);
    $foreign = $this->createForeignPlanet();

    expect($reader->activityAt(activityBody($foreign->getPlanetId(), now()->subMinutes(5)->getTimestamp())))->toBeTrue()
        ->and($reader->activityAt(activityBody($foreign->getPlanetId(), now()->subMinutes(30)->getTimestamp())))->toBeFalse();
});

test('moon-only activity signals the moon facilities in use, not the planet', function (): void {
    $reader = app(ActivityIntelReader::class);
    $planet = $this->createForeignPlanet();
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($planet, 2_000_000, 20);

    $moonActive = activityBody($moon->getPlanetId(), now()->getTimestamp());
    $planetQuiet = activityBody($planet->getPlanetId(), now()->subMinutes(30)->getTimestamp());

    expect($reader->moonOnlyActivity($moonActive, $planetQuiet))->toBeTrue()
        ->and($reader->moonOnlyActivity($moonActive, activityBody($planet->getPlanetId(), now()->getTimestamp())))->toBeFalse();
});

test('intel confidence decays with age, faster for volatile types', function (): void {
    $reader = app(ActivityIntelReader::class);
    $now = 1_800_000_000;

    expect($reader->intelConfidence($now, $now, 24, 'resources'))->toBe(1.0)
        ->and($reader->intelConfidence($now, $now + 7200, 24, 'resources'))->toBe(0.5)
        ->and($reader->intelConfidence($now, $now + 14_400, 24, 'resources'))->toBe(0.0)
        ->and($reader->intelConfidence($now, $now + 7200, 24, 'coordinates'))->toBeGreaterThan(0.9);
});

test('activity probability at eta is zero for an idle body and decays over the window', function (): void {
    $reader = app(ActivityIntelReader::class);
    $foreign = $this->createForeignPlanet();
    $now = now()->getTimestamp();

    $idle = activityBody($foreign->getPlanetId(), $now - 1800);
    expect($reader->activityProbabilityAtEta($idle, $now, $now + 600))->toBe(0.0);

    $active = activityBody($foreign->getPlanetId(), $now);
    expect($reader->activityProbabilityAtEta($active, $now, $now))->toBe(1.0)
        ->and($reader->activityProbabilityAtEta($active, $now, $now + 900))->toBeGreaterThan(0.0)
        ->and($reader->activityProbabilityAtEta($active, $now, $now + 900))->toBeLessThan(0.5);
});

test('owned state publishes per-type confidence and the target activity star', function (): void {
    $foreign = $this->createForeignPlanet();
    activityBody($foreign->getPlanetId(), now()->subMinutes(5)->getTimestamp());

    $reportId = activityReport(
        $this->currentUserId,
        $foreign->getPlanetCoordinates()->galaxy,
        $foreign->getPlanetCoordinates()->system,
        $foreign->getPlanetCoordinates()->position,
        (int) $foreign->getPlanetType()->value,
    );

    $reports = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];

    expect($reports)->toHaveCount(1)
        ->and($reports[0]['report_id'])->toBe($reportId)
        ->and($reports[0]['confidence'])->toBe(1.0)
        ->and($reports[0]['activity'])->toBeTrue();

    Message::query()->where('espionage_report_id', $reportId)->update(['created_at' => now()->subHours(2)]);

    $stale = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];

    expect($stale[0]['confidence'])->toBe(0.5)
        ->and($stale[0]['activity'])->toBeTrue();
});

test('owned state publishes null activity when the target body is gone', function (): void {
    $reportId = activityReport($this->currentUserId, 9, 9, 15, 1);

    $reports = app(PlayerObservationService::class)->ownedState($this->currentUserId)['target_reports'];

    expect($reports)->toHaveCount(1)
        ->and($reports[0]['report_id'])->toBe($reportId)
        ->and($reports[0]['activity'])->toBeNull();
});

function activityBody(int $planetId, int $lastUpdateTimestamp): PlanetService
{
    Planet::query()->whereKey($planetId)->update(['time_last_update' => $lastUpdateTimestamp]);

    return app(PlanetServiceFactory::class)->make($planetId, true);
}

function activityReport(int $playerId, int $galaxy, int $system, int $position, int $planetType): int
{
    $report = new EspionageReport();
    $report->planet_galaxy = $galaxy;
    $report->planet_system = $system;
    $report->planet_position = $position;
    $report->planet_type = $planetType;
    $report->planet_user_id = null;
    $report->resources = ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = [];
    $report->player_info = ['player_name' => 'Target', 'player_status' => 'inactive'];
    $report->save();

    $player = app(PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);

    return $report->id;
}
