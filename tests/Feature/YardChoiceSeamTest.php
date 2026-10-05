<?php

use Modules\AI\Actions\DecideAiYardOrderAction;
use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Choice\EpsilonChoicePolicy;
use Modules\AI\Domain\Choice\TeacherChoicePolicy;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl: the yard choice seam, the second kind of choice point. With the teacher it must be today's planner exactly;
// a recorded row names the order the planner placed; an exploring policy may only order what the host would accept.

beforeEach(function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 31_000 + $this->currentUserId,
        'enabled' => true,
    ]);
    $this->planetSetObjectLevel('shipyard', 6);
    $this->planetSetObjectLevel('research_lab', 4);
    $this->playerSetResearchLevel('combustion_drive', 4);
    $this->playerSetResearchLevel('espionage_technology', 3);
    $this->planetAddResources(new Resources(2_000_000, 1_000_000, 500_000));
});

/** @return array{order: mixed, rows: list<array<string, mixed>>} */
function yardSeamRecord(int $playerId, string $policy, float $epsilon = 0.1): array
{
    $path = tempnam(sys_get_temp_dir(), 'rl-yard-');
    config(['ai.rl.record' => $path, 'ai.rl.policy' => $policy, 'ai.rl.epsilon' => $epsilon]);
    app()->bind(ChoicePolicy::class, fn () => app($policy === 'epsilon' ? EpsilonChoicePolicy::class : TeacherChoicePolicy::class));

    $order = app(DecideAiYardOrderAction::class)->handle($playerId, 'test:yard');
    $rows = array_map(static fn (string $line): array => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($path))));

    return ['order' => $order, 'rows' => $rows];
}

test('with the teacher and no recorder the yard order is the planner\'s own', function (): void {
    config(['ai.rl.record' => null]);
    app()->bind(ChoicePolicy::class, TeacherChoicePolicy::class);

    $planned = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($planned)->not->toBeNull()
        ->and(app(DecideAiYardOrderAction::class)->handle($this->currentUserId, 'test'))->toEqual($planned);
});

test('a recorded yard choice names the order the planner placed, and recording changes nothing', function (): void {
    $planned = app(QueueableUnitPlanner::class)->plan($this->currentUserId);
    ['order' => $order, 'rows' => $rows] = yardSeamRecord($this->currentUserId, 'teacher');

    expect($order)->toEqual($planned)
        ->and($rows)->toHaveCount(1);

    $row = $rows[0];
    expect($row['kind'])->toBe('yard')
        ->and($row['chosen'])->toBe($row['teacher'])
        ->and($row['legal'][$row['teacher']])->toBeTrue()
        ->and($row['objects'][$row['teacher']])->toBe($planned->unitId)
        ->and($row['planet'])->toBe($planned->planetId)
        ->and($row['state'])->toHaveCount(count(EconomyChoiceEncoder::stateNames()))
        ->and($row['cands'][0])->toHaveCount(count(EconomyChoiceEncoder::candidateNames()))
        ->and(count(array_filter($row['legal'])))->toBeGreaterThan(2);
});

test('an exploring policy only orders what the host would accept, and the order follows its pick', function (): void {
    ['order' => $order, 'rows' => $rows] = yardSeamRecord($this->currentUserId, 'epsilon', 1.0);

    expect($rows)->toHaveCount(1);
    $row = $rows[0];
    $picked = $row['objects'][$row['chosen']];

    expect($row['policy'])->toBe('epsilon')
        ->and($row['legal'][$row['chosen']])->toBeTrue()
        ->and($order?->unitId)->toBe($picked);
});
