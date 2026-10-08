<?php

use Modules\AI\Actions\FadeAiGrudgesAction;
use Modules\AI\Domain\Social\AllianceChoice;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Alliance;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function standing(int $playerId, int $general, int $rank): void
{
    OGame\Models\Highscore::unguarded(fn () => OGame\Models\Highscore::updateOrCreate(
        ['player_id' => $playerId],
        ['general' => $general, 'general_rank' => $rank, 'economy' => $general, 'research' => $general],
    ));
}

function grudge(int $from, int $toward, string $threat, string $lastAt): AiRelationship
{
    return AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $from, 'other_player_id' => $toward, 'threat' => $threat, 'trust' => '-0.5000', 'affinity' => '0.3000',
        'revision' => 0, 'last_interaction_at' => $lastAt, 'created_at' => $lastAt, 'updated_at' => $lastAt,
    ]));
}

// 2006 board stories: grudges are about the fighting, and go quiet when it does (grudges.yaml).
test('a grudge stands through the grace, then halves each half-life, and a friendly score is left alone', function (int $quietHours, float $threat, float $trust): void {
    $other = User::factory()->create();
    $relationship = grudge($this->currentUserId, $other->id, '0.8000', now()->subHours($quietHours)->toDateTimeString());

    app(FadeAiGrudgesAction::class)->handle();
    $relationship->refresh();

    expect((float) $relationship->threat)->toEqualWithDelta($threat, 0.01)
        ->and((float) $relationship->trust)->toEqualWithDelta($trust, 0.01)
        ->and((float) $relationship->affinity)->toBe(0.3);
})->with([
    'a fight an hour ago' => [1, 0.8, -0.5],
    'inside the grace' => [23, 0.8, -0.5],
    'one half-life past the grace' => [72, 0.4, -0.25],
    'long forgotten' => [24 + 48 * 8, 0.0, 0.0],
]);

test('a pass does not fade the same hours twice', function (): void {
    $relationship = grudge($this->currentUserId, User::factory()->create()->id, '0.8000', now()->subHours(72)->toDateTimeString());

    app(FadeAiGrudgesAction::class)->handle();
    app(FadeAiGrudgesAction::class)->handle();

    expect((float) $relationship->refresh()->threat)->toEqualWithDelta(0.4, 0.02);
});

test('the enemy of my enemy: between two alike clubs the account asks the one whose members share its grudge', function (): void {
    $enemy = User::factory()->create();
    $clubs = [];
    foreach (['AAA', 'BBB'] as $tag) {
        $founder = User::factory()->create();
        $clubs[$tag] = Alliance::unguarded(fn (): Alliance => Alliance::create([
            'alliance_tag' => $tag, 'alliance_name' => 'Alliance ' . $tag, 'founder_user_id' => $founder->id, 'is_open' => true, 'external_text' => '',
        ]));
        User::query()->whereKey($founder->id)->update(['alliance_id' => $clubs[$tag]->id]);
        standing($founder->id, 100, 2);
    }
    standing($this->currentUserId, 100, 1);
    standing($enemy->id, 100, 3);
    grudge($this->currentUserId, $enemy->id, '0.9000', now()->toDateTimeString());
    grudge((int) $clubs['BBB']->founder_user_id, $enemy->id, '0.9000', now()->toDateTimeString());

    expect(app(AllianceChoice::class)->choose($this->currentUserId)->id)->toBe($clubs['BBB']->id);
});

// "You theoretically get far less raid from others after joining an alliance" (alliance guide): the farmed look for a club.
test('a player two others keep hitting wants a club even when their kind never would', function (int $raiders, bool $wants): void {
    $seed = collect(range(1, 80))->first(function (int $seed): bool {
        OGame\Models\User::query()->whereKey($this->currentUserId)->exists();
        Modules\AI\Models\AiProfile::query()->where('player_id', $this->currentUserId)->delete();
        Modules\AI\Models\AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => Modules\AI\Enums\AiArchetype::Turtle,
            'skill_band' => Modules\AI\Enums\AiSkillBand::Standard, 'random_seed' => $seed, 'enabled' => true]);

        return ! app(Modules\AI\Domain\Social\AllianceAmbition::class)->wantsAlliance($this->currentUserId);
    });
    foreach (range(1, $raiders) as $ignored) {
        grudge($this->currentUserId, User::factory()->create()->id, '0.9000', now()->toDateTimeString());
    }

    expect(app(Modules\AI\Domain\Social\AllianceAmbition::class)->wantsAlliance($this->currentUserId))->toBe($wants);
})->with([
    'one raider' => [1, false],
    'two raiders' => [2, true],
]);
