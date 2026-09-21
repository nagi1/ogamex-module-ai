<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\BuildAiAuthenticityPanelAction;
use Modules\AI\Actions\BuildAiProgressBoardAction;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiScoreSample;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\FixtureAiClock;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(AiQueueModuleTestCase::class);

const ACCOUNTS_NOW = '2026-09-20 12:00:00';

beforeEach(function (): void {
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse(ACCOUNTS_NOW),
    ]));
});

test('the progress board lists accounts with real deltas and orders failures first', function (): void {
    $growing = $this->currentUserId;
    $flat = $this->createUser()->id;
    $silent = $this->createUser()->id;

    foreach ([$growing, $flat, $silent] as $playerId) {
        AiProfile::create(['player_id' => $playerId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => $playerId, 'enabled' => true]);
    }

    aiAccountsSamples($growing, 100, 200);
    aiAccountsSamples($flat, 100, 100);

    AiActionReceipt::create(['player_id' => $growing, 'idempotency_key' => 'growing', 'action_type' => AiActionType::QueueBuilding, 'state' => AiReceiptState::Completed, 'result' => []]);
    AiActionReceipt::create(['player_id' => $flat, 'idempotency_key' => 'flat', 'action_type' => AiActionType::QueueBuilding, 'state' => AiReceiptState::Rejected, 'result' => []]);

    $board = app(BuildAiProgressBoardAction::class)->handle(7);
    $rows = collect($board->rows);
    $orderedIds = $rows->pluck('player_id')->all();

    expect($rows)->toHaveCount(3)
        ->and($rows->firstWhere('player_id', $growing)['delta'])->toBe(100)
        ->and($rows->firstWhere('player_id', $flat)['delta'])->toBe(0)
        ->and($rows->firstWhere('player_id', $silent)['delta'])->toBeNull()
        ->and($rows->firstWhere('player_id', $silent)['alerts'])->toContain('no_action')
        ->and(array_search($silent, $orderedIds, true))->toBeLessThan(array_search($growing, $orderedIds, true));
});

test('the authenticity panel separates an in-window reactor from a late one', function (): void {
    $reactor = $this->currentUserId;
    AiProfile::create(['player_id' => $reactor, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);

    $observedAt = CarbonImmutable::parse(ACCOUNTS_NOW)->subHour();

    AiObservation::create([
        'player_id' => $reactor,
        'source_type' => AiObservationSource::BattleReport,
        'source_id' => 9001,
        'kind' => AiObservationKind::BattleReportObserved,
        'subject_player_id' => $this->createUser()->id,
        'source_time' => $observedAt,
        'observed_at' => $observedAt,
    ]);
    aiAccountsDecision($reactor, $observedAt->addSeconds(150));

    $panel = app(BuildAiAuthenticityPanelAction::class)->handle(7);

    expect($panel->reactionObservations)->toBe(1)
        ->and($panel->reactionsInsideWindow)->toBe(1)
        ->and($panel->reactionsOutsideWindow)->toBe(0);
});

function aiAccountsSamples(int $playerId, int $firstGeneral, int $lastGeneral): void
{
    AiScoreSample::create(['player_id' => $playerId, 'sampled_at' => CarbonImmutable::parse(ACCOUNTS_NOW)->subHour(), 'general' => $firstGeneral, 'military_lost' => 0]);
    AiScoreSample::create(['player_id' => $playerId, 'sampled_at' => CarbonImmutable::parse(ACCOUNTS_NOW), 'general' => $lastGeneral, 'military_lost' => 0]);
}

function aiAccountsDecision(int $playerId, CarbonImmutable $observedAt): AiDecisionTrace
{
    return AiDecisionTrace::create([
        'player_id' => $playerId,
        'selected_action' => AiCandidateActionType::DoNothing,
        'selected_reason' => 'always_available',
        'candidates' => [['action' => 'DoNothing', 'reason' => 'always_available', 'score' => 1.0]],
        'score_components' => [],
        'source_timestamps' => [],
        'input_hash' => hash('sha256', 'decision:' . $playerId . ':' . $observedAt->toDateTimeString()),
        'observed_at' => $observedAt,
        'expires_at' => $observedAt->addDays(30),
    ]);
}
