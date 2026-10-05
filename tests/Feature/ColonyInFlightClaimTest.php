<?php

use Modules\AI\Domain\Decision\QueueableColony;
use Modules\AI\Domain\Decision\QueueableColonyPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\GameMissions\ColonisationMission;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// A colony ship in the air takes its slot and one planet of the cap before it lands. The host's planet count cannot see it, so an
// account that only counted planets sent ship after ship to one slot (12 to the same coordinate in 18 hours, 11 failed on arrival).

function colonyInFlight(int $playerId, QueueableColony $plan): void
{
    $mission = new FleetMission();
    $mission->user_id = $playerId;
    $mission->planet_id_from = null;
    $mission->mission_type = ColonisationMission::getTypeId();
    $mission->galaxy_to = $plan->galaxy;
    $mission->system_to = $plan->system;
    $mission->position_to = $plan->position;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();
}

beforeEach(function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'enabled' => true,
        'archetype' => AiArchetype::Miner->value,
        'skill_band' => AiSkillBand::Standard->value,
        'random_seed' => 42,
    ]);
    $this->planetAddUnit('colony_ship', 1);
    $this->planetAddResources(new Resources(0, 0, 100_000, 0));
});

test('a slot a colony ship is already flying to is not offered again', function (): void {
    $this->playerSetResearchLevel('astrophysics', 8);
    $first = app(QueueableColonyPlanner::class)->plan($this->currentUserId);
    colonyInFlight($this->currentUserId, $first);

    $second = app(QueueableColonyPlanner::class)->plan($this->currentUserId);

    expect($second)->toBeInstanceOf(QueueableColony::class)
        ->and([$second->galaxy, $second->system, $second->position])->not->toBe([$first->galaxy, $first->system, $first->position]);
});

test('a flying colony ship takes a planet of the cap before it lands', function (): void {
    // Astrophysics 4 allows one colony beside the homeworld; the one in the air is that colony.
    $this->playerSetResearchLevel('astrophysics', 4);
    $first = app(QueueableColonyPlanner::class)->plan($this->currentUserId);
    expect($first)->toBeInstanceOf(QueueableColony::class);
    colonyInFlight($this->currentUserId, $first);

    expect(app(QueueableColonyPlanner::class)->plan($this->currentUserId))->toBeNull();
});
