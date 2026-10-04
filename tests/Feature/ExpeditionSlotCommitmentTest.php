<?php

use Modules\AI\Domain\Decision\QueueableExpedition;
use Modules\AI\Domain\Decision\QueueableExpeditionPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

function expeditionSlotProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1_900 + $playerId,
        'enabled' => true,
    ]);
}

/**
 * An expedition already decided whose dispatch has not run yet: it holds its slot against the host's
 * in-flight count, which is exactly the window the live cohort kept planning into.
 */
function expeditionSlotIntent(int $playerId, int $planetId, string $suffix): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $playerId,
        'kind' => AiWorkKind::Expedition,
        'state' => AiWorkState::Pending,
        'due_at' => now()->addMinutes(45),
        'idempotency_key' => 'expedition-slot:' . $playerId . ':' . $suffix,
        'payload' => ['planet_id' => $planetId, 'galaxy' => 1, 'system' => 1, 'position' => 16],
    ]);
}

// Astrophysics 1 grants one expedition slot (the host's floor(sqrt(level))): a second login that plans
// for the same slot only produces a dispatch the host refuses for "too many expeditions".
test('an expedition already decided holds its slot against the next login', function (): void {
    expeditionSlotProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 1);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddResources(new Resources(0, 0, 100_000, 0));

    $planner = app(QueueableExpeditionPlanner::class);
    expect($planner->plan($this->currentUserId))->toBeInstanceOf(QueueableExpedition::class);

    expeditionSlotIntent($this->currentUserId, $this->currentPlanetId, 'first');

    expect($planner->plan($this->currentUserId))->toBeNull();
});

test('an account with a second expedition slot still plans while one intent is open', function (): void {
    expeditionSlotProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 4);
    $this->planetAddUnit('small_cargo', 1);
    $this->planetAddResources(new Resources(0, 0, 100_000, 0));

    $planner = app(QueueableExpeditionPlanner::class);
    expeditionSlotIntent($this->currentUserId, $this->currentPlanetId, 'first');

    expect($planner->plan($this->currentUserId))->toBeInstanceOf(QueueableExpedition::class);

    expeditionSlotIntent($this->currentUserId, $this->currentPlanetId, 'second');

    expect($planner->plan($this->currentUserId))->toBeNull();
});

// The real path: a whole session, whose decision either offers the expedition or does not.
test('a login whose expedition slot is already committed offers no expedition', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 1)
        ->ships('small_cargo', 1)
        ->resources(0, 0, 200_000);

    expeditionSlotIntent($this->currentUserId, $this->currentPlanetId, 'committed');

    $situation->session();

    expect($situation->candidates())->not->toContain('Expedition');
});

test('the same login offers an expedition when the slot is free', function (): void {
    $situation = Situation::of($this)
        ->research('astrophysics', 1)
        ->ships('small_cargo', 1)
        ->resources(0, 0, 200_000)
        ->session();

    expect($situation->candidates())->toContain('Expedition');
});
