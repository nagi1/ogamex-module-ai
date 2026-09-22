<?php

use Illuminate\Foundation\Application;
use Modules\AI\Actions\BuildAiPilotReportAction;
use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Actions\RecordAiStopReasonAction;
use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Models\AiOperabilitySwitch;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiScoreSample;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use OGame\Services\ModuleSlotService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

class AiRouteModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
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

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(AiRouteModuleTestCase::class);

beforeEach(function (): void {
    $this->artisan('ogamex:admin:assign-role', ['username' => $this->currentUsername]);
    app()->bind(AiClock::class, SystemAiClock::class);
});

test('an admin can open the AI module page', function (): void {
    $response = $this->get('/admin/ai');

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toContain('Players')
        ->toContain('Work switch')
        ->and(app(RunAiSession::class))->toBeInstanceOf(RunAiSessionAction::class);
    expect(app(QueueAiBuilding::class))->toBeInstanceOf(QueueAiBuildingAction::class);
});

test('the module adds its page to the admin navigation', function (): void {
    expect(ModuleSlotService::render('admin.nav'))
        ->toContain('admin/ai')
        ->toContain(route('ai.index'));
});

test('the page reports the switch, the configured caps and today\'s refusals', function (): void {
    config([
        'ai.population.profile_cap' => 3,
        'ai.population.dispatch_batch_size' => 10,
    ]);
    aiRouteProfile($this->currentUserId);
    app(RecordAiStopReasonAction::class)->handle(AiStopReason::ActiveSessionCap, ['active_sessions' => 4]);

    $content = $this->get('/admin/ai')->getContent();

    expect($content)->toContain('Universe profile cap')
        ->toContain('Actions dispatched per scheduler pass')
        ->toContain('Players running')
        ->toContain('Sessions in flight reached the cap')
        ->toContain('active_sessions');
});

test('staff can stop and resume new work from the page', function (): void {
    $this->post(route('ai.switch'), ['enabled' => '0', 'reason' => 'pilot paused'])
        ->assertRedirect(route('ai.index'))
        ->assertSessionHas('success', 'Work stopped. Work already in flight finishes.');

    $stopped = AiOperabilitySwitch::query()->sole();

    expect($stopped->enabled)->toBeFalse()
        ->and($stopped->reason)->toBe('pilot paused')
        ->and($stopped->actor_player_id)->toBe($this->currentUserId)
        ->and($this->get('/admin/ai')->getContent())->toContain('New work is stopped.');

    $this->post(route('ai.switch'), ['enabled' => '1', 'reason' => 'pilot resumed'])
        ->assertRedirect(route('ai.index'))
        ->assertSessionHas('success', 'Work resumed.');

    expect(AiOperabilitySwitch::query()->count())->toBe(2)
        ->and(AiOperabilitySwitch::query()->orderByDesc('id')->first()->enabled)->toBeTrue();
});

test('a change without a reason is refused', function (): void {
    $this->post(route('ai.switch'), ['enabled' => '0'])
        ->assertSessionHasErrors('reason');

    expect(AiOperabilitySwitch::query()->count())->toBe(0);
});

test('a player who is not an admin cannot change the switch', function (): void {
    $this->artisan('ogamex:admin:remove-role', ['username' => $this->currentUsername]);

    $this->post(route('ai.switch'), ['enabled' => '0', 'reason' => 'not allowed'])
        ->assertRedirect('/overview');

    expect(AiOperabilitySwitch::query()->count())->toBe(0)
        ->and($this->get('/admin/ai')->status())->toBe(302);
});

/**
 * The page and `ai:pilot-report` must be one reading of one window. The delta line is asserted as the
 * report renders it, so a page that recomputed growth for itself would fail here.
 */
test('the page renders the pilot window from the same answer the report command prints', function (): void {
    aiRouteProfile($this->currentUserId);
    AiScoreSample::create([
        'player_id' => $this->currentUserId,
        'sampled_at' => now()->subHours(2),
        'general' => 500,
        'military_lost' => 10,
    ]);
    AiScoreSample::create([
        'player_id' => $this->currentUserId,
        'sampled_at' => now(),
        'general' => 560,
        'military_lost' => 40,
    ]);

    $answer = app(BuildAiPilotReportAction::class)->handle(7)->toArray();
    $content = $this->get('/admin/ai?tab=pilot&days=7')->getContent();

    expect($answer['profiles'])->toBe(1)
        ->and($answer['score']['accounts'])->toBe(1)
        ->and($answer['score']['samples'])->toBe(2)
        ->and($answer['score']['general_delta'])->toBe(['min' => 60, 'median' => 60, 'max' => 60])
        ->and($answer['score']['military_lost'])->toBe(30)
        ->and($content)->toContain('Pilot window')
        ->toContain('7 days')
        ->toContain('min 60 · median 60 · max 60')
        ->toContain('This read');
});

test('a window that is not offered falls back to one day instead of erroring', function (): void {
    aiRouteProfile($this->currentUserId);

    $content = $this->get('/admin/ai?tab=pilot&days=999')->getContent();

    expect($this->get('/admin/ai?tab=pilot&days=not-a-number')->status())->toBe(200)
        ->and($content)->toContain('Pilot window')
        ->toContain('1 day')
        ->not->toContain('999 days');
});

test('the monitoring tab renders liveness, storage, provider and the account switch', function (): void {
    aiRouteProfile($this->currentUserId);

    $response = $this->get('/admin/ai?tab=monitoring');

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toContain('Stop or resume one account')
        ->toContain('Is it running right now?')
        ->toContain('Storage and retention')
        ->toContain('Provider use');
});

test('the accounts tab renders the authenticity panel and the board', function (): void {
    aiRouteProfile($this->currentUserId);

    $response = $this->get('/admin/ai?tab=accounts');

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toContain('Do the accounts read like players?')
        ->toContain('Accounts');
});

test('the account page renders one account without error', function (): void {
    aiRouteProfile($this->currentUserId);

    $response = $this->get('/admin/ai/account/' . $this->currentUserId);

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toContain('Account');
});

test('the settings tab shows the live controls and the deployment YAML', function (): void {
    $content = $this->get('/admin/ai?tab=settings')->getContent();

    expect($content)->toContain('Live controls')
        ->toContain('Deployment (one YAML file)')
        ->toContain('profile_cap')
        ->toContain('Apply on the server')
        ->toContain('fatima');
});

test('staff can save a live setting', function (): void {
    $this->post(route('ai.settings'), [
        'profile_cap' => '7',
        'active_session_cap' => '0',
        'dispatch_batch_size' => '10',
        'session_action_cap' => '0',
        'monthly_cost_usd' => '5',
        'conversation_reply_ttl_minutes' => '60',
        'affect_decision_weight' => '0',
        'experience_decision_weight' => '0',
        'campaign_mode' => 'observe',
    ])->assertRedirect(route('ai.index', ['tab' => 'settings']))
        ->assertSessionHas('success', 'Settings saved.');

    expect(app(SettingsService::class)->get('ai_population_profile_cap'))->toBe('7');
});

test('a live setting with an invalid value is refused', function (): void {
    $this->post(route('ai.settings'), [
        'profile_cap' => '-1',
    ])->assertSessionHasErrors('profile_cap');

    expect(app(SettingsService::class)->get('ai_population_profile_cap'))->not->toBe('7');
});

function aiRouteProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}
