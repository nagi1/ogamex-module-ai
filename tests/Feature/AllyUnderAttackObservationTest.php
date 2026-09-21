<?php

use Modules\AI\Actions\RecordObservedBattleReportAction;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use OGame\Models\AllianceMember;
use OGame\Models\BattleReport;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    config(['ai.cognition.driver' => 'native']);
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
});

function allyProfileAi(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1,
        'enabled' => true,
    ]);
}

function allyJoinAlliance(int $allianceId, int $userId): void
{
    AllianceMember::unguarded(fn () => AllianceMember::create([
        'alliance_id' => $allianceId,
        'user_id' => $userId,
        'rank_id' => null,
        'joined_at' => now(),
    ]));
    User::whereKey($userId)->update(['alliance_id' => $allianceId]);
}

function allyBattleReport(int $defenderId, int $attackerId): BattleReport
{
    return BattleReport::withoutEvents(fn () => BattleReport::unguarded(fn () => BattleReport::create([
        'planet_galaxy' => 1,
        'planet_system' => 1,
        'planet_position' => 1,
        'planet_user_id' => $defenderId,
        'attacker' => ['player_id' => $attackerId, 'resource_loss' => 100.0],
        'defender' => ['player_id' => $defenderId, 'resource_loss' => 400.0],
    ])));
}

test('a co-member observes an attack on an ally', function (): void {
    $defender = $this->createUser();
    $ally = $this->createUser();
    $attacker = $this->createUser();
    allyProfileAi($defender->id);
    allyProfileAi($ally->id);

    $founder = User::factory()->create();
    $alliance = \OGame\Models\Alliance::unguarded(fn () => \OGame\Models\Alliance::create([
        'alliance_tag' => 'ALLY',
        'alliance_name' => 'Ally Alliance',
        'founder_user_id' => $founder->id,
        'is_open' => true,
    ]));
    allyJoinAlliance($alliance->id, $defender->id);
    allyJoinAlliance($alliance->id, $ally->id);

    $report = allyBattleReport($defender->id, $attacker->id);

    app(RecordObservedBattleReportAction::class)->handle($report->id);

    expect(AiObservation::query()
        ->where('player_id', $ally->id)
        ->where('kind', AiObservationKind::AllyUnderAttack)
        ->where('source_type', AiObservationSource::BattleReport)
        ->where('source_id', $report->id)
        ->exists())->toBeTrue()
        ->and(AiObservation::query()->where('player_id', $ally->id)->sole()->subject_player_id)->toBe($attacker->id)
        ->and(AiObservation::query()->where('player_id', $defender->id)->where('kind', AiObservationKind::AllyUnderAttack)->exists())->toBeFalse();
});

test('a defender with no alliance warns no one', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    allyProfileAi($defender->id);

    $report = allyBattleReport($defender->id, $attacker->id);

    app(RecordObservedBattleReportAction::class)->handle($report->id);

    expect(AiObservation::query()->where('kind', AiObservationKind::AllyUnderAttack)->exists())->toBeFalse();
});
