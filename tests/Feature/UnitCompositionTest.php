<?php

use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\EspionageReport;
use OGame\Models\Resources;
use OGame\Services\MessageService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('the unit planner sizes the cargo batch to the raid payload', function (): void {
    compositionProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('shipyard', 4);
    $this->playerSetResearchLevel('combustion_drive', 2);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('espionage_probe', 1);
    compositionRichReport($this->currentUserId);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toBe('role:cargo:payload')
        ->and($plan->amount)->toBeGreaterThan(1);
});

test('the unit planner does not grow cargo without a raidable report', function (): void {
    compositionProfile($this->currentUserId, AiArchetype::Fleeter);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('shipyard', 4);
    $this->playerSetResearchLevel('combustion_drive', 2);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('espionage_probe', 1);

    // No report: the opening roles are satisfied and nothing asks for more cargo.
    expect(app(QueueableUnitPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('a report whose defence is null does not break unit planning', function (): void {
    compositionProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetSetObjectLevel('shipyard', 4);
    $this->playerSetResearchLevel('combustion_drive', 2);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddUnit('espionage_probe', 1);

    // The host stores a probe that revealed no defence as a null `defense` column,
    // not an empty array; reading it as [] must not throw (the live grand run died
    // here on `foreach ($report->defense as ...)`).
    compositionRichReport($this->currentUserId, defense: null);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toBe('role:cargo:payload');
});

function compositionProfile(int $playerId, AiArchetype $archetype = AiArchetype::Miner): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 16_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A fresh report on a rich target, delivered through the account's own messages. */
function compositionRichReport(int $playerId, ?array $defense = []): void
{
    $foreign = test()->createForeignPlanet();
    $foreignPlayer = $foreign->getPlayer();
    expect($foreignPlayer)->not->toBeNull();

    $report = new EspionageReport();
    $report->planet_galaxy = $foreign->getPlanetCoordinates()->galaxy;
    $report->planet_system = $foreign->getPlanetCoordinates()->system;
    $report->planet_position = $foreign->getPlanetCoordinates()->position;
    $report->planet_type = (int) $foreign->getPlanetType()->value;
    $report->planet_user_id = $foreignPlayer->getId();
    $report->resources = ['metal' => 100_000, 'crystal' => 0, 'deuterium' => 0, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = $defense;
    $report->player_info = ['player_name' => 'Rich', 'player_status' => 'inactive'];
    $report->save();

    $player = app(\OGame\Factories\PlayerServiceFactory::class)->make($playerId, true);
    app(MessageService::class)->sendEspionageReportMessageToPlayer($player, $report->id);
}
