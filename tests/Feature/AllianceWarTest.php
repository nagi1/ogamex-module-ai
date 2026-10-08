<?php

use Modules\AI\Actions\AdvanceAiAlliancePoliticsAction;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use OGame\Models\Alliance;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// A late universe ended with six alliances raiding each other into threat 1.0 and none of them at war as alliances
// (9372). A war is declared where members have been hitting each other, not on a schedule (alliance-politics.md).

// Seed 2 draws a fleeter that betrays a pact, seed 1 one that keeps it (alliance-ambition.yaml).
function allianceWarLeader(int $playerId, string $tag, int $seed = 1): Alliance
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => $seed,
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

    expect(app(AdvanceAiAlliancePoliticsAction::class)->handle())->toBe(1)
        ->and(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War))->toBe([$theirs->id])
        ->and((string) Alliance::query()->whereKey($ours->id)->value('external_text'))->toContain('[THEM]')
        ->and(app(Enemies::class)->of($mate->id))->toHaveKey((int) $theirs->founder_user_id)
        ->and(app(AdvanceAiAlliancePoliticsAction::class)->handle())->toBe(0);
});

test('one bad raid on one member is a grudge, not a war', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $theirs = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'THEM', 'Them');
    // One battle's worth of threat (alliance-war.yaml).
    allianceWarGrudge($this->currentUserId, (int) $theirs->founder_user_id, '0.5000');

    expect(app(AdvanceAiAlliancePoliticsAction::class)->handle())->toBe(0)
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

    expect(app(AdvanceAiAlliancePoliticsAction::class)->handle())->toBe(1)
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

function allianceWarAt(int $allianceId, int $otherAllianceId, AiAllianceStance $stance = AiAllianceStance::War): void
{
    AiAllianceRelation::query()->create([
        'alliance_id' => $allianceId,
        'other_alliance_id' => $otherAllianceId,
        'stance' => $stance,
        'cause' => 'test',
        'started_at' => now(),
    ]);
}

function allianceWarPoints(int $playerId, int $points): void
{
    Highscore::query()->updateOrCreate(['player_id' => $playerId], ['general' => $points]);
}

test('two alliances at war with the same enemy sign a non-aggression pact', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $partner = allianceWarLeader(User::factory()->create()->id, 'PART');
    $enemy = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'ENEM', 'Enemy');
    allianceWarAt($ours->id, $enemy->id);
    allianceWarAt($partner->id, $enemy->id);

    expect(app(AdvanceAiAlliancePoliticsAction::class)->handle())->toBe(1)
        ->and(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::Nap))->toBe([$partner->id])
        ->and(app(AdvanceAiAlliancePoliticsAction::class)->handle())->toBe(0);
});

test('a leader who hates the offering alliance refuses the pact', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $partnerLeader = User::factory()->create();
    $partner = allianceWarLeader($partnerLeader->id, 'PART');
    $enemy = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'ENEM', 'Enemy');
    allianceWarAt($ours->id, $enemy->id);
    allianceWarAt($partner->id, $enemy->id);
    allianceWarGrudge($partnerLeader->id, $this->currentUserId);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceRelation::counterparts($partner->id, AiAllianceStance::Nap))->toBe([]);
});

test('a betraying leader with no war left turns on the partner it has outgrown', function (int $seed, int $partnerPoints, bool $betrayed): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS', $seed);
    $partnerLeader = User::factory()->create();
    $partner = allianceWarLeader($partnerLeader->id, 'PART');
    allianceWarAt($ours->id, $partner->id, AiAllianceStance::Nap);
    allianceWarPoints($this->currentUserId, 10_000);
    allianceWarPoints($partnerLeader->id, $partnerPoints);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War) === [$partner->id])->toBe($betrayed)
        ->and(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::Nap) === [])->toBe($betrayed);
})->with([
    'a betrayer facing a partner a third its size' => [2, 3_000, true],
    'a betrayer facing an equal' => [2, 9_000, false],
    'a loyal leader' => [1, 3_000, false],
]);

test('a betrayer still at war keeps its pact', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS', 2);
    $partnerLeader = User::factory()->create();
    $partner = allianceWarLeader($partnerLeader->id, 'PART');
    $enemy = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'ENEM', 'Enemy');
    allianceWarAt($ours->id, $partner->id, AiAllianceStance::Nap);
    allianceWarAt($ours->id, $enemy->id);
    allianceWarPoints($this->currentUserId, 10_000);
    allianceWarPoints($partnerLeader->id, 1_000);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::Nap))->toBe([$partner->id]);
});

test('an account never scouts its alliance mate or a pact partner', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $this->planetAddUnit('espionage_probe', 1);
    $this->planetAddResources(new Resources(0, 0, 1_000_000));
    $mate = allianceWarMember($ours->id);
    $partner = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'PART', 'Partner');
    allianceWarAt($ours->id, $partner->id, AiAllianceStance::Nap);
    $home = $this->planetService->getPlanetCoordinates();
    Planet::factory()->create(['user_id' => $mate->id, 'galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $home->position === 15 ? 14 : 15]);
    Planet::factory()->create(['user_id' => (int) $partner->founder_user_id, 'galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $home->position === 1 ? 2 : 1]);

    expect(app(QueueableSpyPlanner::class)->plan($this->currentUserId))->toBeNull();
});

test('the two strongest runners-up conspire against an alliance holding most of the points', function (int $dominantPoints, bool $coalition): void {
    $dominantLeader = User::factory()->create();
    $dominant = allianceWarLeader($dominantLeader->id, 'BIG');
    $first = allianceWarLeader($this->currentUserId, 'ONE');
    $secondLeader = User::factory()->create();
    $second = allianceWarLeader($secondLeader->id, 'TWO');
    $smallLeader = User::factory()->create();
    allianceWarLeader($smallLeader->id, 'SMALL');
    allianceWarPoints($dominantLeader->id, $dominantPoints);
    allianceWarPoints($this->currentUserId, 3_000);
    allianceWarPoints($secondLeader->id, 2_000);
    allianceWarPoints($smallLeader->id, 500);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceRelation::counterparts($first->id, AiAllianceStance::War) === [$dominant->id])->toBe($coalition)
        ->and(AiAllianceRelation::counterparts($second->id, AiAllianceStance::War) === [$dominant->id])->toBe($coalition)
        ->and(AiAllianceRelation::counterparts($first->id, AiAllianceStance::Nap) === [$second->id])->toBe($coalition);
})->with([
    'an alliance with two thirds of the points' => [12_000, true],
    'the strongest of four with under half' => [5_000, false],
]);

// Two near-equal clubs of two that traded four battles were never at war: 0.9 of grievance against a line of 1.5 (9406).
test('two raids on a small alliance are a war', function (): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $mate = allianceWarMember($ours->id);
    $theirs = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'THEM', 'Them');
    $raider = allianceWarMember($theirs->id);
    allianceWarGrudge($this->currentUserId, $raider->id, '0.6000');
    allianceWarGrudge($mate->id, (int) $theirs->founder_user_id, '0.3000');

    expect(app(AdvanceAiAlliancePoliticsAction::class)->handle())->toBe(1)
        ->and(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War))->toBe([$theirs->id]);
});

// A war ends by peace, not a timer; the grudges under it fade (alliance-war.yaml, grudges.yaml).
test('a war whose grudges have faded is ended, while a hot or a fresh one stands', function (string $threat, int $hours, bool $ended): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $theirs = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'THEM', 'Them');
    allianceWarMember($theirs->id);
    allianceWarGrudge($this->currentUserId, (int) $theirs->founder_user_id, $threat);
    AiAllianceRelation::query()->create([
        'alliance_id' => $ours->id, 'other_alliance_id' => $theirs->id, 'stance' => AiAllianceStance::War,
        'cause' => 'grievance', 'declared_by' => $this->currentUserId, 'started_at' => now()->subHours($hours),
    ]);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War) === [])->toBe($ended);
})->with([
    'forgotten and old' => ['0.1000', 72, true],
    'forgotten but only hours old' => ['0.1000', 2, false],
    'still hot' => ['0.8000', 72, false],
]);

// Allies are expected to support each other in war-time (alliance guide): a partner's enemy takes one raid, not two.
test('an alliance joins its pact partner\'s war on a single raid', function (bool $partnered, bool $war): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $partner = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'PART', 'Partner');
    $enemy = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'ENMY', 'Enemy');
    allianceWarMember($enemy->id);
    if ($partnered) {
        AiAllianceRelation::query()->create(['alliance_id' => $ours->id, 'other_alliance_id' => $partner->id, 'stance' => AiAllianceStance::Nap,
            'cause' => 'common-enemy', 'declared_by' => $this->currentUserId, 'started_at' => now()]);
        AiAllianceRelation::query()->create(['alliance_id' => $partner->id, 'other_alliance_id' => $enemy->id, 'stance' => AiAllianceStance::War,
            'cause' => 'grievance', 'declared_by' => $partner->founder_user_id, 'started_at' => now()]);
    }
    allianceWarGrudge($this->currentUserId, (int) $enemy->founder_user_id, '0.5000');

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War) === [$enemy->id])->toBe($war);
})->with([
    'a partner at war with the enemy' => [true, true],
    'no partner' => [false, false],
]);

// A weak alliance does not declare a war it cannot trade (the judge on 9437).
test('an alliance far weaker than the one it holds a grievance against does not declare on it', function (int $enemyPoints, bool $war): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $enemy = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'ENMY', 'Enemy');
    $raider = allianceWarMember($enemy->id);
    allianceWarGrudge($this->currentUserId, $raider->id);
    allianceWarGrudge((int) allianceWarMember($ours->id)->id, $raider->id);
    Highscore::unguarded(fn () => Highscore::updateOrCreate(['player_id' => $this->currentUserId], ['general' => 1000, 'general_rank' => 2]));
    Highscore::unguarded(fn () => Highscore::updateOrCreate(['player_id' => $raider->id], ['general' => $enemyPoints, 'general_rank' => 1]));

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War) === [$enemy->id])->toBe($war);
})->with([
    'a peer' => [2000, true],
    'ten times stronger' => [10000, false],
]);

// A club at war does not open a second front (the judge on 9444).
test('an alliance already at war does not declare on a second one', function (int $open, bool $declares): void {
    $ours = allianceWarLeader($this->currentUserId, 'OURS');
    $mate = allianceWarMember($ours->id);
    $targets = [];
    foreach (['ONE', 'TWO'] as $tag) {
        $targets[$tag] = app(AllianceService::class)->createAlliance(User::factory()->create()->id, $tag, $tag . ' club');
        $raider = allianceWarMember($targets[$tag]->id);
        allianceWarGrudge($this->currentUserId, $raider->id);
        allianceWarGrudge($mate->id, $raider->id);
    }
    // The second club is the bigger grievance, so it is the one declared on when nothing holds the leader back.
    allianceWarGrudge($this->currentUserId, (int) $targets['TWO']->founder_user_id);
    if ($open > 0) {
        AiAllianceRelation::query()->create(['alliance_id' => $ours->id, 'other_alliance_id' => $targets['ONE']->id, 'stance' => AiAllianceStance::War,
            'cause' => 'grievance', 'declared_by' => $this->currentUserId, 'started_at' => now()]);
    }

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(in_array($targets['TWO']->id, AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War), true))->toBe($declares);
})->with([
    'no war yet' => [0, true],
    'one war on' => [1, false],
]);
