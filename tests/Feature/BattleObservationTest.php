<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\AppraiseObservedBattleReportAction;
use Modules\AI\Actions\RecordObservedBattleReportAction;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiEmotionalEpisode;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use OGame\Models\BattleReport;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * A battle report is the only legal evidence an AI has that it was attacked, so the
 * reducer must read nothing beyond the stored row, must observe only the two players the
 * row names, and must decline a shape it does not understand instead of guessing who was
 * harmed. The native affect engine answers here, so no driver or sidecar is involved.
 */
beforeEach(function (): void {
    config(['ai.cognition.driver' => 'native']);
    app()->bind(AiClock::class, SystemAiClock::class);
    // The module's own bindings are not active in this suite. Routing through the
    // selector keeps the real appraisal wiring under test instead of a test-local copy.
    app()->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
});

/** @return array<string, mixed> */
function battleSide(int $playerId, float $resourceLoss): array
{
    return ['player_id' => $playerId, 'resource_loss' => $resourceLoss];
}

/**
 * Creates a report without firing the observer, so a test can drive the reducer itself
 * and can supply a report shape the host would not normally write.
 *
 * @param  array<string, mixed>  $overrides
 */
function battleReportFrom(int $defenderPlayerId, array $overrides = []): BattleReport
{
    $attributes = array_merge([
        'planet_galaxy' => 1,
        'planet_system' => 1,
        'planet_position' => 1,
        'planet_user_id' => $defenderPlayerId,
        'attacker' => null,
        'defender' => battleSide($defenderPlayerId, 400.0),
    ], $overrides);

    return BattleReport::withoutEvents(
        fn (): BattleReport => BattleReport::unguarded(fn (): BattleReport => BattleReport::create($attributes)),
    );
}

function battleReportRow(int $defenderPlayerId, mixed $attacker, float $defenderLoss = 400.0): BattleReport
{
    return battleReportFrom($defenderPlayerId, [
        'attacker' => $attacker,
        'defender' => battleSide($defenderPlayerId, $defenderLoss),
    ]);
}

function battleObservedAi(int $playerId, AiArchetype $archetype = AiArchetype::Fleeter, bool $enabled = true): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1,
        'enabled' => $enabled,
    ]);
}

function battleObservation(int $playerId, int $sourceId, AiObservationKind $kind, int $subjectPlayerId): AiObservation
{
    return AiObservation::create([
        'player_id' => $playerId,
        'source_type' => AiObservationSource::BattleReport,
        'source_id' => $sourceId,
        'kind' => $kind,
        'subject_player_id' => $subjectPlayerId,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);
}

test('an AI that came off worse observes the battle and feels anger toward the attacker', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    battleObservedAi($defender->id, AiArchetype::Fleeter);

    $report = battleReportRow($defender->id, battleSide($attacker->id, 100.0), 400.0);

    $recorded = app(RecordObservedBattleReportAction::class)->handle($report->id);

    $observation = AiObservation::query()->where('player_id', $defender->id)->sole();

    expect($recorded)->toBe(1)
        ->and($observation->source_type)->toBe(AiObservationSource::BattleReport)
        ->and($observation->kind)->toBe(AiObservationKind::BattleReportObserved)
        ->and($observation->source_id)->toBe($report->id)
        ->and($observation->subject_player_id)->toBe($attacker->id);

    // Harm is the defender's 400 of the 500 total resource loss, so a Fleeter reads it
    // at full weight against an attacker it has no relationship with yet.
    $episode = AiEmotionalEpisode::query()->where('player_id', $defender->id)->sole();

    expect($episode->emotion)->toBe(AiAffectEmotion::Anger)
        ->and((float) $episode->intensity)->toBe(0.8)
        ->and($episode->source_observation_id)->toBe($observation->id);
});

test('an AI is never given a battle report it did not take part in', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    $bystander = $this->createUser();
    battleObservedAi($bystander->id);

    $report = battleReportRow($defender->id, battleSide($attacker->id, 100.0));

    expect(app(RecordObservedBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and(AiObservation::query()->where('player_id', $bystander->id)->exists())->toBeFalse();
});

test('both participating AIs observe the battle, each naming the player it faced', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    battleObservedAi($defender->id);
    battleObservedAi($attacker->id);

    $report = battleReportRow($defender->id, battleSide($attacker->id, 100.0));

    expect(app(RecordObservedBattleReportAction::class)->handle($report->id))->toBe(2)
        ->and(AiObservation::query()->where('player_id', $defender->id)->sole()->subject_player_id)->toBe($attacker->id)
        ->and(AiObservation::query()->where('player_id', $attacker->id)->sole()->subject_player_id)->toBe($defender->id);
});

test('an AI that did not come off worse is observed but never appraised', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    battleObservedAi($attacker->id, AiArchetype::Fleeter);

    // The attacker lost 50 against the defender's 400, so no adverse emotion is
    // expressible and inventing one would be worse than recording nothing.
    $report = battleReportRow($defender->id, battleSide($attacker->id, 50.0), 400.0);

    app(RecordObservedBattleReportAction::class)->handle($report->id);

    expect(AiObservation::query()->where('player_id', $attacker->id)->exists())->toBeTrue()
        ->and(AiEmotionalEpisode::query()->where('player_id', $attacker->id)->exists())->toBeFalse();
});

test('a report that does not name both players is declined entirely', function (array $overrides): void {
    $defender = $this->createUser();
    battleObservedAi($defender->id);

    $report = battleReportFrom($defender->id, $overrides);

    expect(app(RecordObservedBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and(AiObservation::query()->where('player_id', $defender->id)->exists())->toBeFalse()
        ->and(AiEmotionalEpisode::query()->where('player_id', $defender->id)->exists())->toBeFalse();
})->with([
    'no attacker column' => [['attacker' => null]],
    'attacker without a player' => [['attacker' => ['resource_loss' => 100.0]]],
    'no planet owner' => [['attacker' => ['player_id' => 7], 'planet_user_id' => null]],
]);

test('a report naming the same player on both sides is declined', function (): void {
    $defender = $this->createUser();
    battleObservedAi($defender->id);

    $report = battleReportRow($defender->id, battleSide($defender->id, 100.0), 400.0);

    expect(app(RecordObservedBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and(AiObservation::query()->where('player_id', $defender->id)->exists())->toBeFalse();
});

test('an unknown battle report is ignored', function (): void {
    expect(app(RecordObservedBattleReportAction::class)->handle(PHP_INT_MAX))->toBe(0);
});

test('a report whose losses cannot be read is still observed, but never appraised', function (array $defenderSide, array $attackerSide): void {
    $defender = $this->createUser();
    battleObservedAi($defender->id);

    $report = battleReportFrom($defender->id, [
        'attacker' => $attackerSide,
        'defender' => $defenderSide,
    ]);

    // The AI was legally in this battle, so the observation stands as evidence; only the
    // feeling is withheld, because harm cannot be derived from an unreadable figure.
    expect(app(RecordObservedBattleReportAction::class)->handle($report->id))->toBe(1)
        ->and(AiObservation::query()->where('player_id', $defender->id)->exists())->toBeTrue()
        ->and(AiEmotionalEpisode::query()->where('player_id', $defender->id)->exists())->toBeFalse();
})->with([
    'attacker loss missing' => [['player_id' => 1, 'resource_loss' => 400.0], ['player_id' => 7]],
    'attacker loss not numeric' => [['player_id' => 1, 'resource_loss' => 400.0], ['player_id' => 7, 'resource_loss' => 'a lot']],
    'defender loss missing' => [['player_id' => 1], ['player_id' => 7, 'resource_loss' => 100.0]],
    'defender loss not numeric' => [['player_id' => 1, 'resource_loss' => 'none'], ['player_id' => 7, 'resource_loss' => 100.0]],
]);

test('a battle with no recorded loss on either side is observed but never appraised', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    battleObservedAi($defender->id);

    $report = battleReportRow($defender->id, battleSide($attacker->id, 0.0), 0.0);

    expect(app(RecordObservedBattleReportAction::class)->handle($report->id))->toBe(1)
        ->and(AiEmotionalEpisode::query()->where('player_id', $defender->id)->exists())->toBeFalse();
});

test('a disabled AI is never given an observation', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    battleObservedAi($defender->id, AiArchetype::Fleeter, false);

    $report = battleReportRow($defender->id, battleSide($attacker->id, 100.0));

    expect(app(RecordObservedBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and(AiObservation::query()->where('player_id', $defender->id)->exists())->toBeFalse();
});

test('reducing the same report twice records one observation and one episode', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    battleObservedAi($defender->id);

    $report = battleReportRow($defender->id, battleSide($attacker->id, 100.0));

    $action = app(RecordObservedBattleReportAction::class);

    expect($action->handle($report->id))->toBe(1)
        ->and($action->handle($report->id))->toBe(0);

    expect(AiObservation::query()->where('player_id', $defender->id)->count())->toBe(1)
        ->and(AiEmotionalEpisode::query()->where('player_id', $defender->id)->count())->toBe(1);
});

test('an observation whose report no longer names both players is never appraised', function (array $overrides): void {
    $player = $this->createUser();
    battleObservedAi($player->id);

    $report = battleReportFrom($player->id, $overrides);
    $observation = battleObservation($player->id, $report->id, AiObservationKind::BattleReportObserved, $player->id);

    // The mapper does not assume the reducer ran first: a report it cannot attribute is
    // declined even when an observation already points at it.
    expect(app(AppraiseObservedBattleReportAction::class)->handle($observation->id))->toBeNull()
        ->and(AiEmotionalEpisode::query()->where('player_id', $player->id)->exists())->toBeFalse();
})->with([
    'no attacker column' => [['attacker' => null]],
    'no planet owner' => [['planet_user_id' => null]],
]);

test('an unknown observation is never appraised', function (): void {
    expect(app(AppraiseObservedBattleReportAction::class)->handle(PHP_INT_MAX))->toBeNull();
});

test('an observation that is not a battle is never appraised', function (): void {
    $player = $this->createUser();
    battleObservedAi($player->id);

    $observation = battleObservation($player->id, 4242, AiObservationKind::DirectChatMessageReceived, $player->id);

    expect(app(AppraiseObservedBattleReportAction::class)->handle($observation->id))->toBeNull()
        ->and(AiEmotionalEpisode::query()->where('player_id', $player->id)->exists())->toBeFalse();
});

test('an observation for a disabled AI is never appraised', function (): void {
    $player = $this->createUser();
    battleObservedAi($player->id, AiArchetype::Fleeter, false);

    $observation = battleObservation($player->id, 4242, AiObservationKind::BattleReportObserved, $player->id);

    expect(app(AppraiseObservedBattleReportAction::class)->handle($observation->id))->toBeNull();
});

test('an observation whose battle report no longer exists is never appraised', function (): void {
    $player = $this->createUser();
    battleObservedAi($player->id);

    $observation = battleObservation($player->id, PHP_INT_MAX, AiObservationKind::BattleReportObserved, $player->id);

    expect(app(AppraiseObservedBattleReportAction::class)->handle($observation->id))->toBeNull();
});

test('an observation of a battle the AI did not take part in is never appraised', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    $bystander = $this->createUser();
    battleObservedAi($bystander->id);

    $report = battleReportRow($defender->id, battleSide($attacker->id, 100.0));
    $observation = battleObservation($bystander->id, $report->id, AiObservationKind::BattleReportObserved, $attacker->id);

    expect(app(AppraiseObservedBattleReportAction::class)->handle($observation->id))->toBeNull()
        ->and(AiEmotionalEpisode::query()->where('player_id', $bystander->id)->exists())->toBeFalse();
});

test('an attacker that came off worse feels anger toward the defender it attacked', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    battleObservedAi($attacker->id, AiArchetype::Fleeter);

    // The attacking AI lost 300 against the defender's 100, so it is the harmed party
    // and the defender is the counterparty it resents.
    $report = battleReportRow($defender->id, battleSide($attacker->id, 300.0), 100.0);

    app(RecordObservedBattleReportAction::class)->handle($report->id);

    expect(AiObservation::query()->where('player_id', $attacker->id)->sole()->subject_player_id)->toBe($defender->id);

    $episode = AiEmotionalEpisode::query()->where('player_id', $attacker->id)->sole();

    expect($episode->emotion)->toBe(AiAffectEmotion::Anger)
        ->and((float) $episode->intensity)->toBe(0.75);
});

test('a trusted attacker is read with less anger than an untrusted one', function (): void {
    $defender = $this->createUser();
    $attacker = $this->createUser();
    battleObservedAi($defender->id, AiArchetype::Fleeter);

    AiRelationship::create([
        'player_id' => $defender->id,
        'other_player_id' => $attacker->id,
        'trust' => 0.5,
        'revision' => 1,
    ]);

    $report = battleReportRow($defender->id, battleSide($attacker->id, 100.0), 400.0);

    app(RecordObservedBattleReportAction::class)->handle($report->id);

    // The same 0.8 harm is discounted by an established trust of 0.5.
    expect((float) AiEmotionalEpisode::query()->where('player_id', $defender->id)->sole()->intensity)->toBe(0.4);
});

// The observer itself is covered by CommittedBattleReportObservationTest: the module's
// own boot() is inactive in this suite, and the observer defers through DB::afterCommit,
// which a test transaction never reaches.
