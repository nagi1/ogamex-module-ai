<?php

use Modules\AI\Domain\Decision\AllianceDeterrence;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Alliance;
use OGame\Models\Highscore;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function deterrencePoints(int $playerId, int $general): void
{
    Highscore::unguarded(fn () => Highscore::updateOrCreate(['player_id' => $playerId], ['general' => $general, 'general_rank' => 1]));
}

// "Attacking you means potentially provoking your entire alliance": a strong club's member is left alone, an enemy is not.
test('a member of an alliance far stronger than the raider is not raided unless the raider holds them as an enemy', function (int $clubPoints, bool $enemy, bool $deterred): void {
    $leader = User::factory()->create();
    $club = Alliance::unguarded(fn (): Alliance => Alliance::create(['alliance_tag' => 'BIG', 'alliance_name' => 'Big', 'founder_user_id' => $leader->id, 'is_open' => true]));
    User::query()->whereKey($leader->id)->update(['alliance_id' => $club->id]);
    deterrencePoints($leader->id, $clubPoints);
    deterrencePoints($this->currentUserId, 1000);
    if ($enemy) {
        AiRelationship::unguarded(fn () => AiRelationship::create(['player_id' => $this->currentUserId, 'other_player_id' => $leader->id, 'threat' => Enemies::ENEMY_THREAT, 'revision' => 0]));
    }

    expect(app(AllianceDeterrence::class)->deters($this->currentUserId, $leader->id))->toBe($deterred);
})->with([
    'ten times stronger' => [10_000, false, true],
    'a peer club' => [2_000, false, false],
    'ten times stronger but an enemy' => [10_000, true, false],
]);

test('a solo target is never deterred', function (): void {
    $solo = User::factory()->create();
    deterrencePoints($solo->id, 1_000_000);
    deterrencePoints($this->currentUserId, 10);

    expect(app(AllianceDeterrence::class)->deters($this->currentUserId, $solo->id))->toBeFalse();
});
