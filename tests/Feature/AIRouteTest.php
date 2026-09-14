<?php

use Illuminate\Foundation\Application;
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
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use OGame\Services\ModuleSlotService;
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
        ->and($response->getContent())->toContain('AI Players')
        ->toContain('AI module is loaded')
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
        ->toContain('Enabled AI profiles')
        ->toContain('Sessions in flight reached the cap')
        ->toContain('active_sessions');
});

test('staff can stop and resume new work from the page', function (): void {
    $this->post(route('ai.switch'), ['enabled' => '0', 'reason' => 'pilot paused'])
        ->assertRedirect(route('ai.index'))
        ->assertSessionHas('success', 'AI work stopped. Work already in flight finishes.');

    $stopped = AiOperabilitySwitch::query()->sole();

    expect($stopped->enabled)->toBeFalse()
        ->and($stopped->reason)->toBe('pilot paused')
        ->and($stopped->actor_player_id)->toBe($this->currentUserId)
        ->and($this->get('/admin/ai')->getContent())->toContain('New AI work is stopped.');

    $this->post(route('ai.switch'), ['enabled' => '1', 'reason' => 'pilot resumed'])
        ->assertRedirect(route('ai.index'))
        ->assertSessionHas('success', 'AI work resumed.');

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
