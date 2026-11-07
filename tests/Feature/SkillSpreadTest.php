<?php

use Modules\AI\Domain\Decision\QueueableExpedition;
use Modules\AI\Domain\Decision\QueueableExpeditionPlanner;
use Modules\AI\Domain\Persona\PersonaTaste;
use Modules\AI\Domain\Routine\RoutineProfile;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\RandomSource;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Arm `skill` (behavior/skill.yaml, rl/ogsim/src/tuning.rs): the account's skill quantile sets how often it plays and how
// many of its expedition slots it keeps busy. Astrophysics 9 gives three slots; one expedition is already decided.
beforeEach(function (): void {
    $this->playerSetResearchLevel('astrophysics', 9);
    $this->planetAddUnit('small_cargo', 2);
    $this->planetAddResources(new Resources(0, 0, 200_000, 0));
    AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::Expedition,
        'state' => AiWorkState::Pending,
        'due_at' => now()->addMinutes(45),
        'idempotency_key' => 'skill-spread:' . $this->currentUserId,
        'payload' => ['planet_id' => $this->currentPlanetId, 'galaxy' => 1, 'system' => 1, 'position' => 16],
    ]);
});

function skillSpreadAccount(int $playerId, float|null $quantile): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'activity_band' => AiActivityBand::Regular,
        'random_seed' => 2_700 + $playerId,
        'skill_quantile' => $quantile,
        'enabled' => true,
    ]);
}

test('with the spread on a weak player keeps fewer expedition slots busy and plays less than a strong one', function (): void {
    config(['ai.population.skill' => true]);
    $profile = skillSpreadAccount($this->currentUserId, 0.05);
    $planner = app(QueueableExpeditionPlanner::class);

    // q 0.05: 0.10 + 0.90 q^6 of three slots is one slot, already taken.
    expect($planner->plan($this->currentUserId))->toBeNull();
    $weak = RoutineProfile::fromAiProfile($profile->refresh())->sessionsPerDay;

    // q 0.99: about 0.95 of three slots is all three.
    $profile->update(['skill_quantile' => 0.99]);
    expect($planner->plan($this->currentUserId))->toBeInstanceOf(QueueableExpedition::class);
    $strong = RoutineProfile::fromAiProfile($profile->refresh())->sessionsPerDay;

    // The Miner mix places q 0.05 in Casual (1-3 a day) and q 0.99 in Hardcore (12-18).
    expect($weak)->toBe(2)->and($strong)->toBe(17);
});

test('with the spread on an account draws its quantile once and keeps it', function (): void {
    config(['ai.population.skill' => true]);
    $profile = skillSpreadAccount($this->currentUserId, null);

    app(QueueableExpeditionPlanner::class)->plan($this->currentUserId);
    $drawn = $profile->refresh()->skill_quantile;

    expect($drawn)->not->toBeNull()->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0);
    RoutineProfile::fromAiProfile($profile);
    expect($profile->refresh()->skill_quantile)->toBe($drawn);
});

test('with the spread off nothing changes', function (): void {
    config(['ai.population.skill' => false]);
    $profile = skillSpreadAccount($this->currentUserId, 0.05);

    expect(app(QueueableExpeditionPlanner::class)->plan($this->currentUserId))->toBeInstanceOf(QueueableExpedition::class);

    $diligence = PersonaTaste::fromSeed((int) $profile->random_seed, app(RandomSource::class))->diligence;
    expect(RoutineProfile::fromAiProfile($profile)->sessionsPerDay)->toBe(3 + (int) round(4 * $diligence));

    // Neither a plan nor a routine draws a quantile while the spread is off.
    $profile->update(['skill_quantile' => null]);
    app(QueueableExpeditionPlanner::class)->plan($this->currentUserId);
    RoutineProfile::fromAiProfile($profile->refresh());
    expect($profile->refresh()->skill_quantile)->toBeNull();
});
