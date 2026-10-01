<?php

use Modules\AI\Domain\Decision\QueueableColony;
use Modules\AI\Domain\Decision\QueueableColonyPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * A deterministic per-account walk is the only thing that keeps a cohort from
 * converging on one first slot, so the seed has to reach the chosen coordinate.
 */
function colonisationScanProfile(int $playerId, int $seed = 42): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'enabled' => true,
        'archetype' => AiArchetype::Miner->value,
        'skill_band' => AiSkillBand::Standard->value,
        'random_seed' => $seed,
    ]);
}

function colonisationScanSlot(?QueueableColony $plan): string
{
    if ($plan === null) {
        return 'none';
    }

    return $plan->galaxy . ':' . $plan->system . ':' . $plan->position;
}

test('an account holding a colony ship plans a colony on a colonisable slot', function (): void {
    colonisationScanProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 4);
    $this->planetAddUnit('colony_ship', 1);

    $plan = app(QueueableColonyPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableColony::class)
        ->and($plan->planetId)->toBe($this->currentPlanetId)
        ->and($plan->galaxy)->toBeGreaterThanOrEqual(1)
        ->and($plan->system)->toBeGreaterThanOrEqual(1)
        ->and($plan->position)->toBeBetween(1, 15);
});

test('the scan offsets with the account seed so two accounts do not claim one slot', function (): void {
    $profile = colonisationScanProfile($this->currentUserId);
    $this->playerSetResearchLevel('astrophysics', 4);
    $this->planetAddUnit('colony_ship', 1);

    $first = app(QueueableColonyPlanner::class)->plan($this->currentUserId);

    $profile->update(['random_seed' => 7]);
    $second = app(QueueableColonyPlanner::class)->plan($this->currentUserId);

    expect(colonisationScanSlot($first))->not->toBe('none')
        ->and(colonisationScanSlot($second))->not->toBe('none')
        ->and(colonisationScanSlot($first))->not->toBe(colonisationScanSlot($second));
});

test('the scan plans nothing without a colony ship, an enabled profile or an account', function (): void {
    $profile = colonisationScanProfile($this->currentUserId);

    expect(app(QueueableColonyPlanner::class)->plan($this->currentUserId))->toBeNull();

    $profile->update(['enabled' => false]);
    $this->planetAddUnit('colony_ship', 1);

    expect(app(QueueableColonyPlanner::class)->plan($this->currentUserId))->toBeNull();

    $orphaned = $this->currentUserId + 1_000_000;
    colonisationScanProfile($orphaned);

    expect(app(QueueableColonyPlanner::class)->plan($orphaned))->toBeNull();
});
