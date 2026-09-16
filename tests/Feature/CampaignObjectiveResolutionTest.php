<?php

use Modules\AI\Actions\DeclareAiCampaignObjectiveAction;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Actions\ResolveAiCampaignObjectiveFromBattleReportAction;
use Modules\AI\Models\AiCampaignObjective;
use OGame\Models\BattleReport;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * A declared stronghold completes only from a committed coalition victory at its planet.
 * Victory is the host's own winner semantics read back off the stored report: the last
 * round's surviving ships decide, an empty round list is an uncontested win, and a battle
 * where both sides withdrew is never a win. A draw and a defender win are declined, so the
 * objective board never credits a fight the coalition did not actually take.
 */
function declareObjective(int $planetId): AiCampaignObjective
{
    $campaign = app(OpenAiCampaignAction::class)->handle(now()->toImmutable(), now()->addDay()->toImmutable());

    return app(DeclareAiCampaignObjectiveAction::class)->handle($campaign->id, $planetId);
}

/** @param array<string, mixed> $overrides */
function reportAt(Planet $planet, array $overrides = []): BattleReport
{
    return BattleReport::withoutEvents(
        fn (): BattleReport => BattleReport::unguarded(fn (): BattleReport => BattleReport::create(array_merge([
            'planet_galaxy' => $planet->galaxy,
            'planet_system' => $planet->system,
            'planet_position' => $planet->planet,
            'planet_type' => $planet->planet_type,
            'planet_user_id' => $planet->user_id,
        ], $overrides))),
    );
}

/** @param array<string, int> $attackerShips */
/** @param array<string, int> $defenderShips */
function finalRound(array $attackerShips, array $defenderShips): array
{
    return [['attacker_ships' => $attackerShips, 'defender_ships' => $defenderShips]];
}

test('an attacker victory marks the stronghold objective complete once', function (): void {
    $planet = Planet::query()->find($this->createForeignPlanet()->getPlanetId());
    $objective = declareObjective($planet->id);

    $report = reportAt($planet, ['rounds' => finalRound(['light_fighter' => 5], [])]);

    $resolved = app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id);

    expect($resolved)->toBe(1)
        ->and($objective->refresh()->completed_at)->not->toBeNull();

    expect(app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id))->toBe(0);
});

test('an uncontested attack with no rounds is a victory', function (): void {
    $planet = Planet::query()->find($this->createForeignPlanet()->getPlanetId());
    $objective = declareObjective($planet->id);

    $report = reportAt($planet, ['rounds' => []]);

    expect(app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id))->toBe(1)
        ->and($objective->refresh()->completed_at)->not->toBeNull();
});

test('a draw does not complete the objective', function (): void {
    $planet = Planet::query()->find($this->createForeignPlanet()->getPlanetId());
    $objective = declareObjective($planet->id);

    $report = reportAt($planet, ['rounds' => finalRound(['light_fighter' => 5], ['light_fighter' => 2])]);

    expect(app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and($objective->refresh()->completed_at)->toBeNull();
});

test('a defender win does not complete the objective', function (): void {
    $planet = Planet::query()->find($this->createForeignPlanet()->getPlanetId());
    $objective = declareObjective($planet->id);

    $report = reportAt($planet, ['rounds' => finalRound([], ['light_fighter' => 2])]);

    expect(app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and($objective->refresh()->completed_at)->toBeNull();
});

test('a retreat without combat does not complete the objective', function (): void {
    $planet = Planet::query()->find($this->createForeignPlanet()->getPlanetId());
    $objective = declareObjective($planet->id);

    $report = reportAt($planet, [
        'general' => ['tactical_retreat' => ['attacker_also_retreated' => true]],
        'rounds' => [],
    ]);

    expect(app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and($objective->refresh()->completed_at)->toBeNull();
});

test('a last round without an attacker ship array is not a victory', function (): void {
    $planet = Planet::query()->find($this->createForeignPlanet()->getPlanetId());
    $objective = declareObjective($planet->id);

    $report = reportAt($planet, ['rounds' => [['defender_ships' => ['light_fighter' => 2]]]]);

    expect(app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and($objective->refresh()->completed_at)->toBeNull();
});

test('a victory at a planet that is not a stronghold changes nothing', function (): void {
    $planet = Planet::query()->find($this->createForeignPlanet()->getPlanetId());

    $report = reportAt($planet, ['rounds' => finalRound(['light_fighter' => 5], [])]);

    expect(app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id))->toBe(0)
        ->and(AiCampaignObjective::query()->count())->toBe(0);
});

test('a committed victory resolves its objective without a caller', function (): void {
    $planet = Planet::query()->find($this->createForeignPlanet()->getPlanetId());
    $objective = declareObjective($planet->id);

    BattleReport::unguarded(fn (): BattleReport => BattleReport::create([
        'planet_galaxy' => $planet->galaxy,
        'planet_system' => $planet->system,
        'planet_position' => $planet->planet,
        'planet_type' => $planet->planet_type,
        'planet_user_id' => $planet->user_id,
        'attacker' => ['player_id' => $this->currentUserId],
        'defender' => ['player_id' => $planet->user_id],
        'rounds' => finalRound(['light_fighter' => 5], []),
    ]));

    expect($objective->refresh()->completed_at)->not->toBeNull();
});

test('a victory at coordinates with no planet resolves nothing', function (): void {
    $report = BattleReport::unguarded(fn (): BattleReport => BattleReport::create([
        'planet_galaxy' => 999,
        'planet_system' => 999,
        'planet_position' => 99,
        'planet_type' => 1,
        'planet_user_id' => $this->currentUserId,
        'rounds' => finalRound(['light_fighter' => 5], []),
    ]));

    expect(app(ResolveAiCampaignObjectiveFromBattleReportAction::class)->handle($report->id))->toBe(0);
});
