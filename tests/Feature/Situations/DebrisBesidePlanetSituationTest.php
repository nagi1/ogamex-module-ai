<?php

use Modules\AI\Actions\QueueAiRecycleAction;
use Modules\AI\Contracts\QueueAiRecycle;
use Modules\AI\Domain\Decision\QueueableRecyclePlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\GameMissions\RecycleMission;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(QueueAiRecycle::class, QueueAiRecycleAction::class);
    DebrisField::query()->delete();
    FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->delete();
});

// FLEET-002's situation (scripts/cohort-scenario.php `debris-field`): a field of ordinary size lies at
// the account's own coordinates and a recycler is on hand. A player sends the recycler.
test('a debris field beside the planet and a recycler on hand is a harvest the account sends', function (): void {
    debrisSituationProfile($this->currentUserId);
    $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
    $this->planetAddUnit('recycler', 1);
    $home = $this->planetService->getPlanetCoordinates();
    DebrisField::create(['galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $home->position, 'metal' => 40_000, 'crystal' => 20_000, 'deuterium' => 0]);

    $plan = app(QueueableRecyclePlanner::class)->plan($this->currentUserId);

    expect($plan)->not->toBeNull()
        ->and($plan->targetGalaxy)->toBe($home->galaxy)
        ->and($plan->targetSystem)->toBe($home->system)
        ->and($plan->targetPosition)->toBe($home->position)
        ->and($plan->targetType)->toBe(PlanetType::DebrisField->value);

    $result = app(QueueAiRecycle::class)->handle($this->currentUserId, $this->currentPlanetId, $home->galaxy, $home->system, $home->position, PlanetType::DebrisField->value);

    expect($result->successful)->toBeTrue($result->reason)
        ->and(FleetMission::query()->where('mission_type', RecycleMission::getTypeId())->count())->toBe(1);
});

// FLEET-002's cause: no account ever owned a recycler, because no unit role asks for one. A player
// with debris beside the planet builds the hull that collects it.
test('an account with a field beside its planet and no recycler builds one (FLEET-002)')->todo();

function debrisSituationProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 16_000 + $playerId,
        'enabled' => true,
    ]);
}
