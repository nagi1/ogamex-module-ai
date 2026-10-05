<?php

use Modules\AI\Actions\DecideAiErrandAction;
use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Choice\EpsilonChoicePolicy;
use Modules\AI\Domain\Choice\TeacherChoicePolicy;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl: the errand choice seam, the third kind of choice point (what the login is spent on: a raid, a probe, an
// expedition, a colony, the shipyard, nothing). With the teacher the trace is the engine's own; a recorded row names
// the engine's selection; an exploring policy may only pick an action the engine ranked.

beforeEach(function (): void {
    $this->profile = AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Raider,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 41_000 + $this->currentUserId,
        'enabled' => true,
    ]);
    $this->planetSetObjectLevel('shipyard', 6);
    $this->playerSetResearchLevel('combustion_drive', 4);
    $this->planetAddUnit('small_cargo', 5);
    $this->planetAddUnit('espionage_probe', 3);
    $this->planetAddResources(new Resources(2_000_000, 1_000_000, 500_000));
});

/** @return array{perception: mixed, trace: mixed} */
function errandTrace(AiProfile $profile): array
{
    $perception = app(PlayerPerceptionBuilder::class)->build($profile->player_id, 30);

    return ['perception' => $perception, 'trace' => app(DecisionEngine::class)->decide($profile, $perception, 'test:errand')];
}

/** @return array{trace: mixed, rows: list<array<string, mixed>>} */
function errandRecord(AiProfile $profile, string $policy, float $epsilon = 0.1): array
{
    $path = tempnam(sys_get_temp_dir(), 'rl-errand-');
    config(['ai.rl.record' => $path, 'ai.rl.policy' => $policy, 'ai.rl.epsilon' => $epsilon]);
    app()->bind(ChoicePolicy::class, fn () => app($policy === 'epsilon' ? EpsilonChoicePolicy::class : TeacherChoicePolicy::class));

    ['perception' => $perception, 'trace' => $engine] = errandTrace($profile);
    $trace = app(DecideAiErrandAction::class)->handle($profile, $perception, $engine, 'test:errand');
    $rows = array_map(static fn (string $line): array => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($path))));

    return ['trace' => $trace, 'engine' => $engine, 'rows' => $rows];
}

test('with the teacher and no recorder the trace is the engine\'s own', function (): void {
    config(['ai.rl.record' => null]);
    app()->bind(ChoicePolicy::class, TeacherChoicePolicy::class);
    ['perception' => $perception, 'trace' => $engine] = errandTrace($this->profile);

    expect(app(DecideAiErrandAction::class)->handle($this->profile, $perception, $engine, 'test:errand'))->toBe($engine);
});

test('a recorded errand names the engine\'s selection, and recording changes nothing', function (): void {
    ['trace' => $trace, 'engine' => $engine, 'rows' => $rows] = errandRecord($this->profile, 'teacher');

    expect($trace)->toBe($engine)
        ->and($rows)->toHaveCount(1);

    $row = $rows[0];
    expect($row['kind'])->toBe('errand')
        ->and($row['chosen'])->toBe($row['teacher'])
        ->and($row['legal'][$row['teacher']])->toBeTrue()
        ->and($row['objects'][$row['teacher']])->toBe($engine->selected->candidate->type === \Modules\AI\Enums\AiCandidateActionType::DoNothing ? null : $engine->selected->candidate->type->value)
        ->and($row['state'])->toHaveCount(count(EconomyChoiceEncoder::stateNames()))
        ->and($row['cands'][0])->toHaveCount(count(EconomyChoiceEncoder::candidateNames()))
        ->and(count($row['cands']))->toBeGreaterThan(2);
});

test('an exploring policy only picks an action the engine ranked, and the selection follows its pick', function (): void {
    ['trace' => $trace, 'engine' => $engine, 'rows' => $rows] = errandRecord($this->profile, 'epsilon', 1.0);

    expect($rows)->toHaveCount(1);
    $row = $rows[0];
    $picked = $row['objects'][$row['chosen']];

    expect($row['policy'])->toBe('epsilon')
        ->and($row['legal'][$row['chosen']])->toBeTrue()
        ->and($trace->selected->candidate->type->value)->toBe($picked ?? \Modules\AI\Enums\AiCandidateActionType::DoNothing->value)
        ->and(in_array($trace->selected, $engine->candidates, true))->toBeTrue();
});
