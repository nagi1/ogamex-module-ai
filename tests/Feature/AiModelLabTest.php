<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Modules\AI\Domain\Choice\WideErrandEncoder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiModelLabTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $statuses = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules_statuses.json'), true);
        $statuses['AI'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }
        putenv('MODULES_STATUSES_FILE');
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(AiModelLabTestCase::class);

beforeEach(function (): void {
    $this->artisan('ogamex:admin:assign-role', ['username' => $this->currentUsername]);
    app()->bind(AiClock::class, SystemAiClock::class);
});

/** A tiny policy in the wide errand layout: the lab must load it exactly as the game would. */
function modelLabPolicy(): string
{
    $state = count(WideErrandEncoder::STATE);
    $candidate = count(WideErrandEncoder::candidateNames());
    $layer = static fn (int $in, int $out, float $weight): array => [array_fill(0, $out, array_fill(0, $in, $weight)), array_fill(0, $out, 0.1)];
    $policy = [
        '_errand_feature_version' => WideErrandEncoder::VERSION,
        '_names' => ['state' => WideErrandEncoder::STATE, 'candidate' => WideErrandEncoder::candidateNames()],
        'state_mean' => array_fill(0, $state, 0.0), 'state_std' => array_fill(0, $state, 1.0),
        'cand_mean' => array_fill(0, $candidate, 0.0), 'cand_std' => array_fill(0, $candidate, 1.0),
    ];
    foreach (['state_net.0' => [$state, 2], 'state_net.2' => [2, 2], 'cand_net.0' => [$candidate, 2], 'joint.0' => [4, 2], 'joint.2' => [2, 2], 'joint.4' => [2, 1], 'value.0' => [2, 2], 'value.2' => [2, 1]] as $name => [$in, $out]) {
        [$policy[$name . '.weight'], $policy[$name . '.bias']] = $layer($in, $out, 0.01);
    }
    $path = sys_get_temp_dir() . '/model-lab-' . uniqid('', true) . '.policy.json';
    file_put_contents($path, json_encode($policy));

    return $path;
}

test('the model lab is hidden unless config turns it on', function (): void {
    config(['ai.model_lab.enabled' => false]);

    expect($this->get('/admin/ai/models')->status())->toBe(404)
        ->and($this->get('/admin/ai')->getContent())->not->toContain(route('ai.models'));
});

test('the model lab asks the fleet model about an account in plain words and writes nothing', function (): void {
    $path = modelLabPolicy();
    config(['ai.model_lab.enabled' => true, 'ai.model_lab.fleet_model' => $path, 'ai.model_lab.economy_model' => '/nowhere/x.policy.json']);
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 8,
        'enabled' => true,
    ]);

    $before = [DB::table('ai_decision_traces')->count(), DB::table('ai_work_items')->count(), DB::table('fleet_missions')->count()];
    $response = $this->get('/admin/ai/models?player=' . $this->currentUserId);
    $why = $this->get('/admin/ai/models/why/' . $this->currentUserId);
    unlink($path);

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toContain(__('t_ai.lab_card_fleet'))
        ->toContain(__('t_ai.lab_card_missing'))
        ->toContain(__('t_ai.lab_why_heading', ['player' => $this->currentUserId, 'archetype' => 'Miner']))
        ->toContain(__('t_ai.lab_check_same'))
        ->and($why->status())->toBe(200)
        ->and($why->getContent())->toContain(__('t_ai.lab_why_heading', ['player' => $this->currentUserId, 'archetype' => 'Miner']))
        ->not->toContain('<html')
        ->and($this->get('/admin/ai')->getContent())->toContain(route('ai.models'))
        ->and([DB::table('ai_decision_traces')->count(), DB::table('ai_work_items')->count(), DB::table('fleet_missions')->count()])->toBe($before);
});
