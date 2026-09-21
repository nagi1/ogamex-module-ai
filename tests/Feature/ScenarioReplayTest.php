<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\ReplayAiScenarioAction;
use Modules\AI\Domain\Operability\AiScenarioReplay;
use Modules\AI\Enums\AiCandidateRejectionReason;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\FixtureAiClock;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(AiQueueModuleTestCase::class);

beforeEach(function (): void {
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse('2026-09-14 06:00:00'),
    ]));
});

/**
 * Every authenticity signal the decision engine owns is pinned by one shipped scenario. The set
 * is the replay evidence an operator or reviewer runs with `ai:replay-scenario <name>`; the
 * assertions below are the properties the matrix promises, each under a frozen time and seed.
 */
const AUTHENTICITY_SCENARIOS = [
    'miner-under-visible-raid',      // 1  reaction latency + save: the save fires under inbound
    'late-notice-doomed-save',       // 1/6 a late notice is a doomed save, so a save can fail
    'routine-21-days',               // 2  uptime shape: the dark period decides nothing
    'economy-chain',                 // 3  growth curve: severe scarcity makes the mine the answer
    'colonize-needs-development',    // 3  growth is gated: a colony is withheld until developable
    'divergence-two-accounts',       // 4  self-similarity: two accounts answer differently
    'spy-two-accounts',              // 11 aggregate stats: a free slot probes, score not pinned
    'throttle-mine-when-short',      // 3  mining while short
];

/** Replays one shipped scenario through the real engine without writing anything. */
function replayScenario(string $name): AiScenarioReplay
{
    return app(ReplayAiScenarioAction::class)->handle(app(ReplayAiScenarioAction::class)->pathFor($name));
}

/** @param array<string, mixed> $overrides */
function replayScenarioWithOverrides(string $name, array $overrides): AiScenarioReplay
{
    $action = app(ReplayAiScenarioAction::class);
    /** @var array<string, mixed> $scenario */
    $scenario = json_decode((string) file_get_contents($action->pathFor($name)), true, 512, JSON_THROW_ON_ERROR);

    foreach ($overrides as $field => $value) {
        data_set($scenario, $field, $value);
    }

    $path = sys_get_temp_dir() . '/ai-scenario-' . uniqid('', true) . '.json';
    file_put_contents($path, json_encode($scenario, JSON_THROW_ON_ERROR));

    try {
        return $action->handle($path);
    } finally {
        unlink($path);
    }
}

test('every authenticity scenario ships and replays deterministically', function (): void {
    $action = app(ReplayAiScenarioAction::class);

    expect($action->names())->toContain(...AUTHENTICITY_SCENARIOS);

    foreach (AUTHENTICITY_SCENARIOS as $scenario) {
        $first = $action->handle($action->pathFor($scenario));
        $again = $action->handle($action->pathFor($scenario));

        expect($again->selectedAction)->toBe($first->selectedAction)
            ->and($again->selectedScore)->toBe($first->selectedScore)
            ->and($again->components)->toBe($first->components)
            ->and($again->refusals)->toBe($first->refusals);
    }
});

test('a save fires under inbound fire', function (): void {
    $replay = replayScenario('miner-under-visible-raid');

    expect($replay->selectedAction)->toBe('FleetSave')
        ->and($replay->selectedReason)->toBe('eligible_fleetsave')
        // The report yields a raid candidate this persona's policy refuses, so the
        // boundary is visible in the replay instead of being assumed.
        ->and(collect($replay->alternatives)->pluck('action')->all())->not->toContain('Raid');
});

test('a late notice is a doomed save', function (): void {
    $replay = replayScenario('late-notice-doomed-save');

    expect($replay->refusals)->toHaveKey('report:77')
        ->and($replay->refusals['report:77'])->toBe(AiCandidateRejectionReason::StaleTargetIntel->value)
        ->and(collect($replay->alternatives)->pluck('action')->all())->not->toContain('FleetSave');
});

test('a routine dark-period session decides nothing', function (): void {
    $replay = replayScenario('routine-21-days');

    expect($replay->selectedAction)->toBe('DoNothing')
        ->and($replay->selectedReason)->toBe('always_available');
});

test('severe scarcity makes the mine the answer over a ship habit', function (): void {
    $replay = replayScenario('economy-chain');

    expect($replay->selectedAction)->toBe('Build')
        ->and($replay->selectedReason)->toBe('published_capability:build')
        // The scarcity boost is what outranks the fleeter's ship habit.
        ->and($replay->components['resource_need'])->toBeGreaterThan(30.0);
});

test('a colony is withheld until the account can develop it', function (): void {
    $replay = replayScenario('colonize-needs-development');

    expect(collect($replay->alternatives)->pluck('action')->all())->not->toContain('Colonize')
        ->and($replay->selectedAction)->toBe('DoNothing');
});

test('two accounts answer the same observation differently', function (): void {
    $miner = replayScenario('divergence-two-accounts');
    $fleeter = replayScenarioWithOverrides('divergence-two-accounts', ['persona.archetype' => 'Fleeter']);

    expect($miner->selectedAction)->toBe('Build')
        ->and($fleeter->selectedAction)->not->toBe($miner->selectedAction)
        ->and($fleeter->selectedAction)->toBeIn(['Spy', 'QueueUnits']);
});

test('a free fleet slot probes a neighbour', function (): void {
    $replay = replayScenario('spy-two-accounts');

    expect($replay->selectedAction)->toBe('Spy')
        ->and($replay->selectedReason)->toBe('published_capability:spy');
});

test('mining while short throttles the mine instead of spending', function (): void {
    $replay = replayScenario('throttle-mine-when-short');

    expect($replay->selectedAction)->toBe('ThrottleMine')
        ->and($replay->selectedReason)->toBe('published_capability:throttle_mine');
});
