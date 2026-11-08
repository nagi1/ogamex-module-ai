<?php

use Modules\AI\Actions\RecordObservedBattleReportAction;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use OGame\Models\Alliance;
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
    $alliance = Alliance::unguarded(fn () => Alliance::create([
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

test('an ally under attack marks the attacker and warms the ally', function (): void {
    $defender = $this->createUser();
    $ally = $this->createUser();
    $attacker = $this->createUser();
    allyProfileAi($defender->id);
    allyProfileAi($ally->id);

    $founder = User::factory()->create();
    $alliance = Alliance::unguarded(fn () => Alliance::create([
        'alliance_tag' => 'BLOC',
        'alliance_name' => 'Bloc Alliance',
        'founder_user_id' => $founder->id,
        'is_open' => true,
    ]));
    allyJoinAlliance($alliance->id, $defender->id);
    allyJoinAlliance($alliance->id, $ally->id);

    $report = allyBattleReport($defender->id, $attacker->id);
    app(RecordObservedBattleReportAction::class)->handle($report->id);

    $toAttacker = AiRelationship::query()->where('player_id', $ally->id)->where('other_player_id', $attacker->id)->sole();
    $toDefender = AiRelationship::query()->where('player_id', $ally->id)->where('other_player_id', $defender->id)->sole();

    expect((float) $toAttacker->threat)->toBe(0.20)
        ->and((float) $toAttacker->trust)->toBe(-0.05)
        ->and((float) $toDefender->affinity)->toBe(0.10)
        ->and((float) $toDefender->social_importance)->toBe(0.05);
});

test('a defender with no alliance warns no one', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    allyProfileAi($defender->id);

    $report = allyBattleReport($defender->id, $attacker->id);

    app(RecordObservedBattleReportAction::class)->handle($report->id);

    expect(AiObservation::query()->where('kind', AiObservationKind::AllyUnderAttack)->exists())->toBeFalse();
});

test('probes shot down over an ally are spying, not an attack: no alarm reaches the alliance', function (): void {
    // Live 8 Nov 2026: 147 of 184 "ally under attack" alarms in six hours were probes, and each sent a defence fleet.
    $defender = $this->createUser();
    $ally = $this->createUser();
    $spy = $this->createUser();
    allyProfileAi($defender->id);
    allyProfileAi($ally->id);
    $founder = User::factory()->create();
    $alliance = Alliance::unguarded(fn () => Alliance::create([
        'alliance_tag' => 'SPY', 'alliance_name' => 'Spied Alliance', 'founder_user_id' => $founder->id, 'is_open' => true,
    ]));
    allyJoinAlliance($alliance->id, $defender->id);
    allyJoinAlliance($alliance->id, $ally->id);
    $report = static fn (array $units): BattleReport => BattleReport::withoutEvents(fn () => BattleReport::unguarded(fn () => BattleReport::create([
        'planet_galaxy' => 1, 'planet_system' => 1, 'planet_position' => 1, 'planet_user_id' => $defender->id,
        'attacker' => ['player_id' => $spy->id, 'resource_loss' => 5000.0, 'units' => $units],
        'defender' => ['player_id' => $defender->id, 'resource_loss' => 0.0],
    ])));
    $alarms = static fn (): int => AiObservation::query()->where('player_id', $ally->id)->where('kind', AiObservationKind::AllyUnderAttack)->count();

    app(RecordObservedBattleReportAction::class)->handle($report(['espionage_probe' => 5])->id);
    expect($alarms())->toBe(0);

    // The same spy coming back with fighters is an attack the alliance hears about.
    app(RecordObservedBattleReportAction::class)->handle($report(['espionage_probe' => 1, 'light_fighter' => 20])->id);
    expect($alarms())->toBe(1);
});
