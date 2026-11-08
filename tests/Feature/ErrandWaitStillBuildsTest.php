<?php

use Modules\AI\Actions\DecideAiErrandAction;
use Modules\AI\Domain\Choice\WideErrandEncoder;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Live 8 Nov 2026: three miners on the fleet model sat on their starting stock all night. The model's "wait" became a
// "do nothing" login, and a do-nothing login never refills the build queues. A player whose fleet has nothing to do
// still builds: the wait keeps the fleet home and the login takes the engine's best non-fleet action.

/** A model that always prefers the waiting row (its score follows the candidate column "wait"). */
function waitingErrandModel(): string
{
    $state = count(WideErrandEncoder::STATE);
    $names = WideErrandEncoder::candidateNames();
    $zeros = static fn (int $in, int $out): array => array_fill(0, $out, array_fill(0, $in, 0.0));
    $policy = [
        '_errand_feature_version' => WideErrandEncoder::VERSION,
        '_names' => ['state' => WideErrandEncoder::STATE, 'candidate' => $names],
        'state_mean' => array_fill(0, $state, 0.0), 'state_std' => array_fill(0, $state, 1.0),
        'cand_mean' => array_fill(0, count($names), 0.0), 'cand_std' => array_fill(0, count($names), 1.0),
        'state_net.0.weight' => $zeros($state, 2), 'state_net.0.bias' => [0.0, 0.0],
        'state_net.2.weight' => $zeros(2, 2), 'state_net.2.bias' => [0.0, 0.0],
        'cand_net.0.weight' => [array_map(static fn (string $name): float => $name === 'wait' ? 1.0 : 0.0, $names), array_fill(0, count($names), 0.0)],
        'cand_net.0.bias' => [0.0, 0.0],
        'joint.0.weight' => [[0.0, 0.0, 1.0, 0.0], [0.0, 0.0, 0.0, 0.0]], 'joint.0.bias' => [0.0, 0.0],
        'joint.2.weight' => [[1.0, 0.0], [0.0, 0.0]], 'joint.2.bias' => [0.0, 0.0],
        'joint.4.weight' => [[1.0, 0.0]], 'joint.4.bias' => [0.0],
    ];
    $path = sys_get_temp_dir() . '/errand-wait-' . uniqid('', true) . '.policy.json';
    file_put_contents($path, json_encode($policy));

    return $path;
}

test('a fresh miner whose fleet model waits still spends the login on its economy', function (): void {
    config(['ai.rl.errand_model' => $path = waitingErrandModel(), 'ai.rl.fleet_errands' => 'model', 'ai.rl.fleet_errands_scripted_personas' => []]);
    $profile = AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 4, 'enabled' => true]);
    // A young miner's planet: a shipyard, no ships yet, an empty build queue and stock to spend.
    $this->planetSetObjectLevel('shipyard', 2);
    $this->planetSetObjectLevel('robot_factory', 2);
    $this->planetAddResources(new Resources(40_000, 40_000, 20_000));
    $perception = app(PlayerPerceptionBuilder::class)->build($profile->player_id, 30);
    $ranked = app(DecisionEngine::class)->decide($profile, $perception, 'test:wait');
    $order = array_values(array_filter($ranked->candidates, static fn ($scored): bool => $scored->candidate->type === AiCandidateActionType::QueueUnits));
    $economy = array_filter($ranked->candidates, static fn ($scored): bool => in_array($scored->candidate->type, [AiCandidateActionType::Build, AiCandidateActionType::Research], true));
    // As on the live account: the engine's own pick is a shipyard order (a fleet errand row), the model waits instead.
    $engine = app()->makeWith(DecisionTrace::class, ['perception' => $ranked->perception, 'candidates' => $ranked->candidates, 'selected' => $order[0], 'rejections' => $ranked->rejections, 'inputHash' => $ranked->inputHash]);

    $trace = app(DecideAiErrandAction::class)->handle($profile, $perception, $engine, 'test:wait');
    unlink($path);

    expect($order)->not->toBeEmpty()
        ->and($economy)->not->toBeEmpty()
        ->and($trace->selected->candidate->type)->toBeIn([AiCandidateActionType::Build, AiCandidateActionType::Research])
        ->and(in_array($trace->selected, $engine->candidates, true))->toBeTrue();
});
