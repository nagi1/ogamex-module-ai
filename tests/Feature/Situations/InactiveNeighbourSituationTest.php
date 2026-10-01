<?php

use Modules\AI\Actions\QueueAiRaidAction;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\MessageService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiRaid::class, QueueAiRaidAction::class);
});

// ATK-001's situation (scripts/cohort-scenario.php `inactive-neighbour`): the host calls a player
// inactive once `users.time` is older than seven days. An opening account with a few cargo ships
// raids that neighbour when its stock pays for the trip, and leaves one who played six days ago alone.
test('an opening account raids a neighbour inactive for eight days whose stock beats the trip', function (): void {
    inactiveSituationAccount($this->currentUserId, $this->currentPlanetId);
    $foreign = inactiveSituationNeighbour($this->createForeignPlanet(), 8);
    $reportId = inactiveSituationReport($this->currentUserId, $foreign);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeInstanceOf(QueueableRaid::class);
});

test('an opening account leaves a neighbour who played six days ago alone', function (): void {
    inactiveSituationAccount($this->currentUserId, $this->currentPlanetId);
    $foreign = inactiveSituationNeighbour($this->createForeignPlanet(), 6);
    $reportId = inactiveSituationReport($this->currentUserId, $foreign);

    expect(app(RaidPlanner::class)->plan($this->currentUserId, $reportId))->toBeNull();
});

/** One planet (the opening phase raids inactives only), cargo for the haul, ordinary stock. */
function inactiveSituationAccount(int $playerId, int $planetId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 17_000 + $playerId,
        'enabled' => true,
    ]);
    Planet::query()->where('user_id', $playerId)->where('id', '!=', $planetId)->update(['destroyed' => 1]);
    test()->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    test()->planetAddUnit('small_cargo', 20);
}

/** The owner's last-activity stamp sits in a non-fillable column, so it is set on the model directly. */
function inactiveSituationNeighbour(PlanetService $foreign, int $daysAgo): PlanetService
{
    $user = User::query()->find($foreign->getPlayer()->getId());
    $user->time = (string) now()->subDays($daysAgo)->timestamp;
    $user->save();
    $foreign->addResources(new Resources(300_000, 200_000, 100_000));

    return $foreign;
}

function inactiveSituationReport(int $playerId, PlanetService $foreign): int
{
    $coordinates = $foreign->getPlanetCoordinates();
    $report = new EspionageReport();
    $report->planet_galaxy = $coordinates->galaxy;
    $report->planet_system = $coordinates->system;
    $report->planet_position = $coordinates->position;
    $report->planet_type = 1;
    $report->planet_user_id = $foreign->getPlayer()->getId();
    $report->resources = ['metal' => 300_000, 'crystal' => 200_000, 'deuterium' => 100_000, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = [];
    $report->defense = [];
    $report->player_info = ['player_name' => 'Neighbour', 'player_status' => 'inactive'];
    $report->save();

    app(MessageService::class)->sendEspionageReportMessageToPlayer(
        app(OGame\Factories\PlayerServiceFactory::class)->make($playerId, true),
        $report->id,
    );

    return $report->id;
}
