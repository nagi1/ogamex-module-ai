<?php

use Modules\AI\Actions\DeclareAiAllianceWarsAction;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Alliance;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// A late universe ended with six alliances raiding each other into threat 1.0 and none of them at war as alliances
// (9372). A war is declared where members have been hitting each other, not on a schedule (alliance-politics.md).

function allianceWarLeader(int $playerId, string $tag): Alliance
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 41_000 + $playerId,
        'enabled' => true,
    ]);

    return app(AllianceService::class)->createAlliance($playerId, $tag, $tag . ' Club');
}

function allianceWarMember(int $allianceId): User
{
    $member = User::factory()->create();
    User::query()->whereKey($member->id)->update(['alliance_id' => $allianceId]);

    return $member;
}

function allianceWarGrudge(int $holderId, int $otherId, string $threat = '1.0000'): void
{
    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $holderId,
        'other_player_id' => $otherId,
        'threat' => $threat,
        'trust' => '-1.0000',
        'revision' => 0,
    ]));
}

test('an alliance whose members two rivals have been raiding declares war on their alliance', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $mate = allianceWarMember($ours->id);
    $theirs = Alliance::query()->findOrFail(app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'THEM', 'Them')->id);
    $raider = allianceWarMember($theirs->id);
    allianceWarGrudge($this->currentUserId, $raider->id);
    allianceWarGrudge($mate->id, $raider->id);

    expect(app(DeclareAiAllianceWarsAction::class)->handle())->toBe(1)
        ->and(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War))->toBe([$theirs->id])
        ->and((string) Alliance::query()->whereKey($ours->id)->value('external_text'))->toContain('[THEM]')
        ->and(app(Enemies::class)->of($mate->id))->toHaveKey((int) $theirs->founder_user_id)
        ->and(app(DeclareAiAllianceWarsAction::class)->handle())->toBe(0);
});

test('one bad raid on one member is a grudge, not a war', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $theirs = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'THEM', 'Them');
    allianceWarGrudge($this->currentUserId, (int) $theirs->founder_user_id);

    expect(app(DeclareAiAllianceWarsAction::class)->handle())->toBe(0)
        ->and(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War))->toBe([]);
});

test('the side the other holds the larger grievance against leaves the declaration to them', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $mate = allianceWarMember($ours->id);
    $other = User::factory()->create();
    $theirs = allianceWarLeader($other->id, 'THEM');
    $raider = allianceWarMember($theirs->id);
    allianceWarGrudge($this->currentUserId, $raider->id, '0.8000');
    allianceWarGrudge($mate->id, $raider->id, '0.8000');
    allianceWarGrudge($other->id, $mate->id);
    allianceWarGrudge($raider->id, $mate->id);

    expect(app(DeclareAiAllianceWarsAction::class)->handle())->toBe(1)
        ->and(AiAllianceRelation::query()->value('alliance_id'))->toBe($theirs->id);
});

test('a non-aggression pact keeps a grudge-holder from treating its partner as an enemy', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $theirs = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'THEM', 'Them');
    allianceWarGrudge($this->currentUserId, (int) $theirs->founder_user_id);
    AiAllianceRelation::query()->create([
        'alliance_id' => $ours->id,
        'other_alliance_id' => $theirs->id,
        'stance' => AiAllianceStance::Nap,
        'cause' => 'test',
        'started_at' => now(),
    ]);

    expect(app(Enemies::class)->of($this->currentUserId))->not->toHaveKey((int) $theirs->founder_user_id);
});
