<?php

use Modules\AI\Actions\DecideAiEconomyStepsAction;
use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Domain\Choice\ChoiceCandidate;
use Modules\AI\Domain\Choice\ChoicePoint;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Choice\EpsilonChoicePolicy;
use Modules\AI\Domain\Choice\SocketChoicePolicy;
use Modules\AI\Domain\Choice\TeacherChoicePolicy;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Resources;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl: the economy choice seam. With the teacher it must be today's planner exactly; recording must
// describe the choice the planner made; any other policy may only pick what the host would accept.

beforeEach(function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 21_000 + $this->currentUserId,
        'enabled' => true,
    ]);
    $stock = new Resources(200_000, 100_000, 50_000);
    $this->planetAddResources($stock);
    $this->secondPlanetService->addResources($stock);
});

/** @return array<string, int> step key => object id, so two step lists compare by what they queue where */
function choiceSeamSteps(array $steps): array
{
    $keyed = [];
    foreach ($steps as $step) {
        $keyed[$step instanceof QueueableResearch ? 'lab' : 'planet:' . $step->planetId] = $step instanceof QueueableResearch ? $step->researchId : $step->buildingId;
    }
    ksort($keyed);

    return $keyed;
}

/** @return list<array<string, mixed>> */
function choiceSeamRecord(int $playerId, string $policy, ?float $epsilon = null): array
{
    $path = tempnam(sys_get_temp_dir(), 'rl-choices-');
    config(['ai.rl.record' => $path, 'ai.rl.policy' => $policy, 'ai.rl.epsilon' => $epsilon ?? 0.1]);
    app()->bind(ChoicePolicy::class, fn () => app(match ($policy) {
        'epsilon' => EpsilonChoicePolicy::class,
        'socket' => SocketChoicePolicy::class,
        default => TeacherChoicePolicy::class,
    }));

    $steps = app(DecideAiEconomyStepsAction::class)->handle($playerId, 'test:' . $policy);
    $rows = array_map(static fn (string $line): array => json_decode($line, true), array_filter(explode("\n", (string) file_get_contents($path))));

    return ['steps' => $steps, 'rows' => $rows];
}

test('with the teacher and no recorder the steps are the planner\'s own', function (): void {
    config(['ai.rl.record' => null]);
    app()->bind(ChoicePolicy::class, TeacherChoicePolicy::class);

    $decided = app(DecideAiEconomyStepsAction::class)->handle($this->currentUserId, 'test');
    $planned = app(QueueableBuildingPlanner::class)->steps($this->currentUserId);

    expect(choiceSeamSteps($decided))->toBe(choiceSeamSteps($planned));
});

test('a recorded choice names the step the planner placed, and recording changes nothing', function (): void {
    ['steps' => $steps, 'rows' => $rows] = choiceSeamRecord($this->currentUserId, 'teacher');
    $planned = choiceSeamSteps(app(QueueableBuildingPlanner::class)->steps($this->currentUserId));

    expect(choiceSeamSteps($steps))->toBe($planned)
        ->and($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $key = $row['kind'] === 'research' ? 'lab' : 'planet:' . $row['planet'];
        expect($row['chosen'])->toBe($row['teacher'])
            ->and($row['legal'][$row['teacher']])->toBeTrue()
            ->and($row['objects'][$row['teacher']])->toBe($planned[$key] ?? null)
            ->and($row['state'])->toHaveCount(count(EconomyChoiceEncoder::stateNames()))
            ->and($row['cands'][0])->toHaveCount(count(EconomyChoiceEncoder::candidateNames()));
    }
});

test('an exploring policy only queues what the host would accept, and the steps follow its pick', function (): void {
    ['steps' => $steps, 'rows' => $rows] = choiceSeamRecord($this->currentUserId, 'epsilon', 1.0);
    $placed = choiceSeamSteps($steps);

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        $key = $row['kind'] === 'research' ? 'lab' : 'planet:' . $row['planet'];
        expect($row['policy'])->toBe('epsilon')
            ->and($row['legal'][$row['chosen']])->toBeTrue()
            ->and($placed[$key] ?? null)->toBe($row['objects'][$row['chosen']]);
    }
});

test('a socket policy with no server answering falls back to the planner', function (): void {
    config(['ai.rl.socket' => sys_get_temp_dir() . '/no-such-rl-server.sock']);
    ['steps' => $steps, 'rows' => $rows] = choiceSeamRecord($this->currentUserId, 'socket');

    expect(choiceSeamSteps($steps))->toBe(choiceSeamSteps(app(QueueableBuildingPlanner::class)->steps($this->currentUserId)));
    foreach ($rows as $row) {
        expect($row['chosen'])->toBe($row['teacher']);
    }
});

/** @return array{path: string, log: string, process: resource} a policy server in its own process that answers each request with the next listed row */
function choiceSeamServer(array $answers): array
{
    $path = sys_get_temp_dir() . '/rl-seam-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.sock';
    $log = $path . '.log';
    $code = '$s = stream_socket_server("unix://' . $path . '"); $c = stream_socket_accept($s, 10); '
        . 'foreach (' . var_export($answers, true) . ' as $a) { $l = fgets($c); if ($l === false) break; '
        . 'file_put_contents(' . var_export($log, true) . ', $l, FILE_APPEND); fwrite($c, json_encode(["index" => $a]) . "\n"); }';
    $process = proc_open([PHP_BINARY, '-r', $code], [], $pipes);
    for ($waited = 0; !file_exists($path) && $waited < 100; $waited++) {
        usleep(50_000);
    }

    return ['path' => $path, 'log' => $log, 'process' => $process];
}

function choiceSeamCandidate(?int $objectId, bool $legal, bool $payableLater): ChoiceCandidate
{
    return app()->makeWith(ChoiceCandidate::class, ['objectId' => $objectId, 'pass' => null, 'reason' => $objectId === null ? 'wait' : 'test', 'legal' => $legal, 'features' => [0.0], 'payableLater' => $payableLater]);
}

afterEach(function (): void {
    // The policy keeps one connection per process; a test's server is gone when the test ends.
    (new ReflectionProperty(SocketChoicePolicy::class, 'connection'))->setValue(null, null);
});

test('a row the planet cannot pay yet but its mines will is payable later, and nothing blocked otherwise is', function (): void {
    $this->planetService->deductResources($this->planetService->getResources());
    $planner = app(QueueableBuildingPlanner::class);
    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);

    $rows = [];
    foreach ($planner->choiceSets($this->currentUserId, $planner->steps($this->currentUserId), $player) as $set) {
        if (!$set['research'] && $set['planet']->getPlanetId() === $this->planetService->getPlanetId()) {
            $rows = $set['candidates'];
        }
    }

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        $blockedByMoreThanPrice = $planner->refusal($this->planetService, $row['candidate'], null, true) !== null;
        // Payable now implies payable later; a row blocked by a requirement, a field or a busy yard never is.
        expect(!$row['legal'] || $row['payableLater'])->toBeTrue()
            ->and(!$blockedByMoreThanPrice || !$row['payableLater'])->toBeTrue();
    }
    expect(array_filter($rows, static fn (array $row): bool => !$row['legal'] && $row['payableLater']))->not->toBeEmpty();
});

test('a socket answer naming a row to save for waits, a payable row is queued, and an unbuildable row falls back', function (): void {
    $wait = choiceSeamCandidate(null, true, false);
    $point = app()->makeWith(ChoicePoint::class, [
        'playerId' => $this->currentUserId, 'planetId' => $this->planetService->getPlanetId(), 'kind' => ChoicePoint::BUILDING, 'state' => [0.0],
        'candidates' => [$wait, choiceSeamCandidate(1, true, false), choiceSeamCandidate(2, false, true), choiceSeamCandidate(3, false, false)],
        'teacherIndex' => 1, 'key' => 'test',
    ]);
    $profile = AiProfile::query()->where('player_id', $this->currentUserId)->firstOrFail();

    $server = choiceSeamServer([1, 2, 3]);
    config(['ai.rl.socket' => $server['path']]);
    $policy = app(SocketChoicePolicy::class);
    $before = SocketChoicePolicy::fallbacks();

    expect($policy->choose($point, $profile))->toBe(1)
        ->and($policy->choose($point, $profile))->toBe(0)
        ->and(SocketChoicePolicy::fallbacks())->toBe($before)
        ->and($policy->choose($point, $profile))->toBe(1)
        ->and(SocketChoicePolicy::fallbacks())->toBe($before + 1);

    $request = json_decode(explode("\n", (string) file_get_contents($server['log']))[0], true);
    expect($request['buildable'])->toBe([true, true, true, false])
        ->and($request['legal'])->toBe([true, true, false, false])
        ->and($request['teacher'])->toBe(1);
    proc_close($server['process']);
    @unlink($server['log']);
});
