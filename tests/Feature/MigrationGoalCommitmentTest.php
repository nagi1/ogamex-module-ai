<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiGoal;
use Modules\AI\Models\AiProfile;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * ARCH-GOALS (architecture step 4, remainder): a goal lasts from one login to the next.
 *
 * Nothing persists intent today, so every login re-decides from scratch and "save for a colony ship"
 * or "build the crash fleet" cannot exist. This is the red spec for an `ai_goals` table read through
 * one `GoalBoard`, with commitment (a goal holds until met or past `abandon_after`).
 *
 * RED until the table, model and board exist.
 */
test('a goal is committed once and re-committing it does not duplicate it', function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
    ]);

    $board = app(\Modules\AI\Domain\Login\GoalBoard::class);
    $board->commit($this->currentUserId, 'colonies', 4, now()->addHour());
    $board->commit($this->currentUserId, 'colonies', 4, now()->addHour());

    expect(AiGoal::query()->where('player_id', $this->currentUserId)->count())->toBe(1)
        ->and((int) AiGoal::query()->where('player_id', $this->currentUserId)->value('target'))->toBe(4);
});

test('a goal past its window is abandoned instead of held forever', function (): void {
    $board = app(\Modules\AI\Domain\Login\GoalBoard::class);
    $board->commit($this->currentUserId, 'plasma', 8, now()->subMinute());

    expect($board->active($this->currentUserId))->toBe([])
        ->and(AiGoal::query()->where('player_id', $this->currentUserId)->count())->toBe(0);
});
