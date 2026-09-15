<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Modules\AI\Actions\ExplainAiDecisionAction;
use Modules\AI\Actions\ReplayAiScenarioAction;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiOperabilitySwitch;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiStopCounter;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use Modules\AI\Tests\Support\FixtureAiClock;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';
require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(AiQueueModuleTestCase::class);

const INSPECTION_NOW = '2026-09-14 06:00:00';
const INSPECTION_SCENARIO = 'miner-under-visible-raid';
const INSPECTION_COORDINATE = '4:120:7';

beforeEach(function (): void {
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse(INSPECTION_NOW),
    ]));
});

/**
 * Writes the trace one session would have recorded, including a parameter an operator page must
 * not print.
 */
function aiRecordedDecision(int $playerId): AiDecisionTrace
{
    return AiDecisionTrace::create([
        'player_id' => $playerId,
        'selected_action' => AiCandidateActionType::Build,
        'selected_reason' => 'published_capability:build',
        'candidates' => [
            [
                'action' => 'Build',
                'reason' => 'published_capability:build',
                'parameters' => ['target_coordinate' => INSPECTION_COORDINATE],
                'source_timestamps' => [],
                'score' => 55.5,
                'components' => ['resource_need' => 30.0],
            ],
            [
                'action' => 'DoNothing',
                'reason' => 'always_available',
                'parameters' => [],
                'source_timestamps' => [],
                'score' => 5.0,
                'components' => [],
            ],
        ],
        'score_components' => [
            'resource_need' => 30.0,
            'archetype_preference' => 25.0,
            'rejections' => ['report:12' => 'expired'],
        ],
        'source_timestamps' => ['resources' => '2026-09-14T05:55:00+00:00'],
        'input_hash' => hash('sha256', 'recorded-decision:' . $playerId),
        'observed_at' => CarbonImmutable::parse(INSPECTION_NOW),
        'expires_at' => CarbonImmutable::parse(INSPECTION_NOW)->addDays(30),
    ]);
}

/**
 * The module's own tables, so a read-only claim can be tested rather than asserted in prose.
 *
 * @return array<string, int>
 */
function aiModuleRowCounts(): array
{
    return [
        'profiles' => AiProfile::query()->count(),
        'work_items' => AiWorkItem::query()->count(),
        'traces' => AiDecisionTrace::query()->count(),
        'switches' => AiOperabilitySwitch::query()->count(),
        'stops' => AiStopCounter::query()->count(),
    ];
}

test('the explanation reads a trace, its deciding components and what lost', function (): void {
    $newest = aiRecordedDecision($this->currentUserId);

    $explanation = app(ExplainAiDecisionAction::class)->forTrace($newest->id);

    expect($explanation)->not->toBeNull()
        ->and($explanation->traceId)->toBe($newest->id)
        ->and($explanation->selectedAction)->toBe('Build')
        ->and($explanation->selectedReason)->toBe('published_capability:build')
        ->and($explanation->selectedScore)->toBe(55.5)
        ->and($explanation->components)->toBe(['resource_need' => 30.0, 'archetype_preference' => 25.0])
        ->and($explanation->refusals)->toBe(['report:12' => 'expired'])
        ->and($explanation->alternatives)->toBe([
            ['action' => 'Build', 'score' => 55.5],
            ['action' => 'DoNothing', 'score' => 5.0],
        ])
        ->and($explanation->evidence)->toBe(['resources' => '2026-09-14T05:55:00+00:00']);

    expect(app(ExplainAiDecisionAction::class)->forTrace(PHP_INT_MAX))->toBeNull();
});

test('the explanation of the newest decisions is scoped to one player and to the universe', function (): void {
    $other = $this->createUser();
    aiRecordedDecision($this->currentUserId);
    $newest = aiRecordedDecision($other->id);

    $mine = app(ExplainAiDecisionAction::class)->forPlayer($other->id);
    $universe = app(ExplainAiDecisionAction::class)->latest();

    expect($mine)->toHaveCount(1)
        ->and($mine[0]->traceId)->toBe($newest->id)
        ->and($universe[0]->traceId)->toBe($newest->id)
        ->and(app(ExplainAiDecisionAction::class)->forPlayer($this->currentUserId))->toHaveCount(1);
});

test('the explain command prints the decision without the parameters it carried', function (): void {
    aiRecordedDecision($this->currentUserId);

    Artisan::call('ai:explain-decision', ['--player' => $this->currentUserId, '--limit' => 1]);
    $output = Artisan::output();

    expect($output)->toContain('Build')
        ->toContain('published_capability:build')
        ->toContain('refused report:12 (expired)')
        ->not->toContain(INSPECTION_COORDINATE);
});

test('the explain command reports when nothing matches', function (): void {
    $this->artisan('ai:explain-decision', ['--trace' => PHP_INT_MAX])
        ->expectsOutputToContain('No recorded decision matches.')
        ->assertExitCode(1);
});

test('the explain command explains one decision by id and one account by option', function (): void {
    $trace = aiRecordedDecision($this->currentUserId);

    $this->artisan('ai:explain-decision', ['--trace' => $trace->id])
        ->expectsOutputToContain('Trace ' . $trace->id)
        ->assertExitCode(0);

    $this->artisan('ai:explain-decision', ['--player' => $this->currentUserId, '--limit' => 1])
        ->expectsOutputToContain('chose Build')
        ->assertExitCode(0);
});

test('the explain command explains the newest decisions of the universe by default', function (): void {
    aiRecordedDecision($this->currentUserId);

    $this->artisan('ai:explain-decision')
        ->expectsOutputToContain('chose Build')
        ->assertExitCode(0);
});

// The human lines and the JSON are two renderings of one read, and the redaction survives both: a
// review can parse a decision without being handed the coordinates a candidate carried.
test('the explain command answers in the same redacted fields as JSON', function (): void {
    $trace = aiRecordedDecision($this->currentUserId);

    Artisan::call('ai:explain-decision', ['--trace' => $trace->id, '--json' => true]);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBe([
        [
            'trace_id' => $trace->id,
            'player_id' => $this->currentUserId,
            'observed_at' => INSPECTION_NOW,
            'selected_action' => 'Build',
            'selected_reason' => 'published_capability:build',
            'selected_score' => 55.5,
            'components' => ['resource_need' => 30.0, 'archetype_preference' => 25.0],
            'alternatives' => [
                ['action' => 'Build', 'score' => 55.5],
                ['action' => 'DoNothing', 'score' => 5.0],
            ],
            'refusals' => ['report:12' => 'expired'],
            'evidence' => ['resources' => '2026-09-14T05:55:00+00:00'],
        ],
    ])->and(json_encode($payload))->not->toContain(INSPECTION_COORDINATE);
});

test('a partial trace is explained without inventing a score or a component', function (): void {
    $trace = AiDecisionTrace::create([
        'player_id' => $this->currentUserId,
        'selected_action' => AiCandidateActionType::Build,
        'selected_reason' => 'published_capability:build',
        'candidates' => [],
        'score_components' => ['note' => 'not a number', 'resource_need' => 12.0],
        'source_timestamps' => [],
        'input_hash' => hash('sha256', 'partial-trace'),
        'observed_at' => CarbonImmutable::parse(INSPECTION_NOW),
        'expires_at' => CarbonImmutable::parse(INSPECTION_NOW)->addDays(30),
    ]);

    $explanation = app(ExplainAiDecisionAction::class)->forTrace($trace->id);

    expect($explanation->selectedScore)->toBe(0.0)
        ->and($explanation->components)->toBe(['resource_need' => 12.0])
        ->and($explanation->alternatives)->toBe([])
        ->and($explanation->refusals)->toBe([]);
});

test('a scenario whose only report is stale replays with that report refused', function (): void {
    $path = writeScenarioWithOverrides(['input.target_reports.0.expires_at' => 1789365000]);

    $this->artisan('ai:replay-scenario', ['scenario' => $path])
        ->expectsOutputToContain('refused report:77')
        ->assertExitCode(0);

    unlink($path);
});

test('a shipped scenario replays to the same decision every time and writes nothing', function (): void {
    $before = aiModuleRowCounts();
    $action = app(ReplayAiScenarioAction::class);
    $path = $action->pathFor(INSPECTION_SCENARIO);

    $replay = $action->handle($path);
    $again = $action->handle($path);

    expect($replay->name)->toBe(INSPECTION_SCENARIO)
        ->and($replay->persona)->toBe('Miner/Standard seed 7')
        ->and($replay->selectedAction)->toBe('FleetSave')
        ->and($replay->selectedReason)->toBe('eligible_fleetsave')
        // The report yields a raid candidate that this persona's policy does not allow, so the
        // boundary is visible in the replay instead of being assumed.
        ->and(collect($replay->alternatives)->pluck('action')->all())->not->toContain('Raid')
        ->and($again->selectedAction)->toBe($replay->selectedAction)
        ->and($again->selectedScore)->toBe($replay->selectedScore)
        ->and($again->components)->toBe($replay->components)
        ->and(aiModuleRowCounts())->toBe($before);
});

test('the replay command explains the scenario and states that it wrote nothing', function (): void {
    $this->artisan('ai:replay-scenario', ['scenario' => INSPECTION_SCENARIO])
        ->expectsOutputToContain('FleetSave')
        ->expectsOutputToContain('this replay wrote nothing.')
        ->assertExitCode(0);
});

test('an unknown scenario name is refused with the names that exist', function (): void {
    $this->artisan('ai:replay-scenario', ['scenario' => 'no-such-scenario'])
        ->expectsOutputToContain('Unknown scenario: no-such-scenario')
        ->assertExitCode(1);
});

test('a malformed scenario reports what is wrong', function (string|array $override, string $expected): void {
    $path = is_string($override) ? writeScenarioContent($override) : writeScenarioWithOverrides($override);

    $this->artisan('ai:replay-scenario', ['scenario' => $path])
        ->expectsOutputToContain($expected)
        ->assertExitCode(1);

    unlink($path);
})->with('malformed scenario fields');

test('a scenario missing a whole section is refused before anything is read', function (): void {
    $path = writeScenarioContent(json_encode([
        'name' => 'broken',
        'input' => [],
        'decision_key' => 'x',
    ], JSON_THROW_ON_ERROR));

    $this->artisan('ai:replay-scenario', ['scenario' => $path])
        ->expectsOutputToContain('Scenario is missing "persona"')
        ->assertExitCode(1);

    unlink($path);
});

test('a path that does not exist is refused by the replay action itself', function (): void {
    expect(fn () => app(ReplayAiScenarioAction::class)->handle('/no/such/scenario.json'))
        ->toThrow(RuntimeException::class, 'Scenario file not found');
});

function writeScenarioContent(string $content): string
{
    $path = sys_get_temp_dir() . '/ai-scenario-' . uniqid('', true) . '.json';
    file_put_contents($path, $content);

    return $path;
}

/**
 * @param array<string, mixed> $overrides
 */
function writeScenarioWithOverrides(array $overrides): string
{
    $shipped = app(ReplayAiScenarioAction::class)->pathFor(INSPECTION_SCENARIO);
    /** @var array<string, mixed> $scenario */
    $scenario = json_decode((string) file_get_contents($shipped), true, 512, JSON_THROW_ON_ERROR);

    foreach ($overrides as $field => $value) {
        data_set($scenario, $field, $value);
    }

    return writeScenarioContent(json_encode($scenario, JSON_THROW_ON_ERROR));
}

dataset('malformed scenario fields', [
    'not json' => ['plain text, not JSON', 'Scenario is not valid JSON'],
    'persona is not an object' => [['persona' => 'Miner'], 'Scenario field "persona" must be an object.'],
    'input is not an object' => [['input' => 'observed'], 'Scenario field "input" must be an object.'],
    'decision key is not a scalar' => [['decision_key' => null], 'Scenario field "decision_key" must be a scalar value.'],
    'player id is not a scalar' => [['input.player_id' => null], 'Scenario field "player_id" must be a scalar value.'],
    'resource is not a number' => [['input.planets.0.resources.metal' => 'plenty'], 'Scenario planet resource "metal" must be a number.'],
    'report is not an object' => [['input.target_reports.0' => 'report'], 'Scenario field "target_reports" must be a list of objects.'],
    'capability flag is not a boolean' => [['input.available_actions.build' => 'yes'], 'Scenario flag "build" must be true or false.'],
    'source timestamp is not a string' => [['input.source_timestamps.resources' => 17], 'Scenario label "resources" must be a string.'],
    'unknown archetype' => [['persona.archetype' => 'Wizard'], 'Scenario names an unknown archetype: Wizard.'],
    'unknown skill band' => [['persona.skill_band' => 'Legendary'], 'Scenario names an unknown skill band: Legendary.'],
]);

test('the page lists recent decisions and replays a shipped scenario on request', function (): void {
    $this->artisan('ogamex:admin:assign-role', ['username' => $this->currentUsername]);
    aiRecordedDecision($this->currentUserId);

    $index = $this->get('/admin/ai');
    $replayed = $this->get('/admin/ai?replay=' . INSPECTION_SCENARIO);
    $unknown = $this->get('/admin/ai?replay=' . urlencode('../config/config'));

    expect($index->status())->toBe(200)
        ->and($index->getContent())->toContain('Build')
        ->not->toContain(INSPECTION_COORDINATE)
        ->and($replayed->getContent())->toContain('FleetSave')
        ->and($unknown->status())->toBe(200)
        ->and($unknown->getContent())->toContain('Unknown scenario');
});
