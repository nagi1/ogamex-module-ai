<?php

use Modules\AI\Domain\Choice\ChoiceCandidate;
use Modules\AI\Domain\Choice\ChoicePoint;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Choice\EconomyPolicyModel;
use Modules\AI\Domain\Choice\ModelChoicePolicy;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The simulator's economy model (best-es3-s10) runs in PHP: its columns are picked by name from the game's own economy
// choice point, its eight account columns come from the highscore, and the clock is read in the universe it learned in.

/** A tiny model of the simulator's shape whose scores follow one candidate column, so the pick is known. */
function economyModelFile(float $trainingSpeed, int $favouredColumn): string
{
    $state = count(EconomyPolicyModel::STATE) + count(EconomyPolicyModel::ACCOUNT);
    $candidate = count(EconomyPolicyModel::CANDIDATE) + 1;
    $zeros = static fn (int $in, int $out): array => array_fill(0, $out, array_fill(0, $in, 0.0));
    $policy = [
        'state_mean' => [log($trainingSpeed), ...array_fill(0, $state - 1, 0.0)], 'state_std' => array_fill(0, $state, 1.0),
        'cand_mean' => array_fill(0, $candidate, 0.0), 'cand_std' => array_fill(0, $candidate, 1.0),
        'state_net.0.weight' => $zeros($state, 2), 'state_net.0.bias' => [0.0, 0.0],
        'state_net.2.weight' => $zeros(2, 2), 'state_net.2.bias' => [0.0, 0.0],
        'cand_net.0.weight' => [array_map(static fn (int $i): float => $i === $favouredColumn ? 1.0 : 0.0, range(0, $candidate - 1)), array_fill(0, $candidate, 0.0)],
        'cand_net.0.bias' => [0.0, 0.0],
        'joint.0.weight' => [[0.0, 0.0, 1.0, 0.0], [0.0, 0.0, 0.0, 0.0]], 'joint.0.bias' => [0.0, 0.0],
        'joint.2.weight' => [[1.0, 0.0], [0.0, 0.0]], 'joint.2.bias' => [0.0, 0.0],
        'joint.4.weight' => [[1.0, 0.0]], 'joint.4.bias' => [0.0],
    ];
    $path = sys_get_temp_dir() . '/economy-model-' . uniqid('', true) . '.policy.json';
    file_put_contents($path, json_encode($policy));

    return $path;
}

/** @param array<string, float> $values candidate columns by the encoder's names */
function economyRow(int $objectId, bool $legal, array $values, bool $payableLater = false): ChoiceCandidate
{
    $names = EconomyChoiceEncoder::candidateNames();
    $features = array_fill(0, count($names), 0.0);
    foreach ($values as $name => $value) {
        $features[array_search($name, $names, true)] = $value;
    }

    return app()->makeWith(ChoiceCandidate::class, ['objectId' => $objectId, 'pass' => 'routine', 'reason' => 'test', 'legal' => $legal, 'features' => $features, 'payableLater' => $payableLater]);
}

/** @param list<ChoiceCandidate> $rows */
function economyPoint(int $playerId, array $rows, int $teacher = 0): ChoicePoint
{
    $wait = app()->makeWith(ChoiceCandidate::class, ['objectId' => null, 'pass' => null, 'reason' => 'wait', 'legal' => true, 'features' => array_fill(0, count(EconomyChoiceEncoder::candidateNames()), 0.0)]);

    return economyPointOf($playerId, ChoicePoint::BUILDING, array_fill(0, count(EconomyChoiceEncoder::stateNames()), 0.0), [$wait, ...$rows], $teacher);
}

/**
 * @param list<float> $state
 * @param list<ChoiceCandidate> $rows
 */
function economyPointOf(int $playerId, string $kind, array $state, array $rows, int $teacher): ChoicePoint
{
    return app()->makeWith(ChoicePoint::class, ['playerId' => $playerId, 'planetId' => 1, 'kind' => $kind, 'state' => $state, 'candidates' => $rows, 'teacherIndex' => $teacher, 'key' => 'test']);
}

test('only a file of the simulator\'s economy shape loads', function (): void {
    $good = economyModelFile(8.0, 0);
    $errand = json_decode((string) file_get_contents($good), true);
    $errand['cand_mean'] = array_fill(0, 47, 0.0);

    expect(EconomyPolicyModel::parse((string) file_get_contents($good)))->not->toBeNull()
        ->and(EconomyPolicyModel::parse((string) json_encode($errand)))->toBeNull()
        ->and(EconomyPolicyModel::parse('not json'))->toBeNull();
    unlink($good);
});

test('a fast universe is read in the training universe\'s hours, and the speed column is the training speed', function (): void {
    app(SettingsService::class)->set('economy_speed', 800);
    $model = EconomyPolicyModel::parse((string) file_get_contents($path = economyModelFile(8.0, 0)));
    unlink($path);
    // A build of 1 hour at 800x is 100 hours at 8x; a gain of 800 an hour at 800x is 8 an hour at 8x; a price is a price.
    $point = economyPoint($this->currentUserId, [economyRow(1, true, ['build_hours' => log1p(1.0), 'gain_ph' => log1p(800.0), 'cost_metal' => log1p(5000.0)])]);

    $columns = $model->columns($point);
    $row = array_combine([...EconomyPolicyModel::CANDIDATE, EconomyPolicyModel::ADVICE], $columns['candidates'][1]);

    expect($columns['state'][0])->toEqualWithDelta(log(8.0), 1e-9)
        ->and(expm1($row['build_hours']))->toEqualWithDelta(100.0, 1e-6)
        ->and(expm1($row['gain_ph']))->toEqualWithDelta(8.0, 1e-6)
        ->and(expm1($row['cost_metal']))->toEqualWithDelta(5000.0, 1e-6)
        ->and($row[EconomyPolicyModel::ADVICE])->toBe(0.0)
        ->and($columns['candidates'][0][count(EconomyPolicyModel::CANDIDATE)])->toBe(1.0);
});

test('the model takes its best row, waits when that row cannot be paid yet, and never scores a refused row', function (): void {
    app(SettingsService::class)->set('economy_speed', 8);
    // Scores follow cost_metal: the dearest row the host offers wins.
    $model = EconomyPolicyModel::parse((string) file_get_contents($path = economyModelFile(8.0, 0)));
    unlink($path);
    $cheap = economyRow(1, true, ['cost_metal' => 1.0]);
    $dear = economyRow(2, true, ['cost_metal' => 5.0]);
    $refused = economyRow(3, false, ['cost_metal' => 9.0]);
    $later = economyRow(4, false, ['cost_metal' => 7.0], payableLater: true);

    expect($model->choose(economyPoint($this->currentUserId, [$cheap, $dear, $refused])))->toBe(2)
        ->and(array_keys($model->scores(economyPoint($this->currentUserId, [$cheap, $dear, $refused]))))->toBe([0, 1, 2])
        ->and($model->choose(economyPoint($this->currentUserId, [$cheap, $dear, $later])))->toBe(0);
});

test('the model policy answers building and research choices and leaves every other choice to the planner', function (): void {
    app(SettingsService::class)->set('economy_speed', 8);
    config(['ai.rl.economy_model' => $path = economyModelFile(8.0, 0)]);
    $profile = AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 3, 'enabled' => true]);
    $building = economyPoint($this->currentUserId, [economyRow(1, true, ['cost_metal' => 1.0]), economyRow(2, true, ['cost_metal' => 5.0])], teacher: 1);
    $yard = economyPointOf($this->currentUserId, ChoicePoint::YARD, $building->state, $building->candidates, 1);

    expect(app(ModelChoicePolicy::class)->choose($building, $profile))->toBe(2)
        ->and(app(ModelChoicePolicy::class)->choose($yard, $profile))->toBe(1);
    config(['ai.rl.economy_model' => null]);
    expect(app(ModelChoicePolicy::class)->choose($building, $profile))->toBe(1);
    unlink($path);
});

test('the trained best-es3-s10 loads and decides on a real account\'s choice', function (): void {
    $path = dirname(__DIR__, 4) . '/storage/rl/ogsim/runs/train4/best-es3-s10.policy.json';
    if (!is_file($path)) {
        $this->markTestSkipped('the trained model is not in this checkout');
    }
    $model = EconomyPolicyModel::parse((string) file_get_contents($path));
    $point = economyPoint($this->currentUserId, [economyRow(1, true, ['cost_metal' => log1p(60.0), 'cost_crystal' => log1p(15.0), 'build_hours' => log1p(0.01)])], teacher: 1);

    expect($model)->not->toBeNull()
        ->and($model->trainingSpeed())->toBeGreaterThan(1.0)
        ->and(array_map(is_finite(...), $model->scores($point)))->each->toBeTrue()
        ->and($model->choose($point))->toBeIn([0, 1]);
});
