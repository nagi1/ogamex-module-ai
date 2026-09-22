<?php

use Illuminate\Support\Facades\Queue;
use Modules\AI\Actions\BuildAiCampaignControlAction;
use Modules\AI\Actions\RunAiCampaignControlAction;
use Modules\AI\Enums\AiCampaignControl;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Jobs\RunAiCampaignControlJob;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignObjective;
use Modules\AI\Models\AiOperationLog;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';

uses(AiQueueModuleTestCase::class);

beforeEach(function (): void {
    $this->artisan('ogamex:admin:assign-role', ['username' => $this->currentUsername]);
});

function aiCampaignControlCampaign(): AiCampaign
{
    return AiCampaign::query()->create([
        'state' => AiCampaignState::Preparing,
        'starts_at' => now(),
        'ends_at' => now()->addDays(7),
        'faction_momentum' => 0,
    ]);
}

test('the campaigns tab renders the reading and the controls', function (): void {
    aiCampaignControlCampaign();

    $content = $this->get('/admin/ai?tab=campaigns')->getContent();

    expect($content)->toContain('Coalition campaign')
        ->toContain('Open a campaign')
        ->toContain('Declare a stronghold')
        ->toContain('Advance the campaign');
});

test('opening a campaign queues an audited run', function (): void {
    Queue::fake();

    $this->post(route('ai.campaigns'), [
        'control' => 'campaign:open',
        'starts_at' => '2026-09-22T12:00',
        'ends_at' => '2026-09-29T12:00',
    ])->assertRedirect(route('ai.index', ['tab' => 'campaigns']));

    $log = AiOperationLog::query()->sole();

    expect($log->operation)->toBe('campaign:open')
        ->and($log->status)->toBe('queued')
        ->and($log->actor_player_id)->toBe($this->currentUserId);

    Queue::assertPushed(RunAiCampaignControlJob::class);
});

test('opening a campaign with an end before its start is refused', function (): void {
    Queue::fake();

    $this->post(route('ai.campaigns'), [
        'control' => 'campaign:open',
        'starts_at' => '2026-09-29T12:00',
        'ends_at' => '2026-09-22T12:00',
    ])->assertSessionHasErrors('ends_at');

    expect(AiOperationLog::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the campaign control job opens a campaign and records the result', function (): void {
    Queue::fake();

    $params = ['starts_at' => '2026-09-22T12:00', 'ends_at' => '2026-09-29T12:00'];
    $log = app(RunAiCampaignControlAction::class)->handle(AiCampaignControl::Open, $params, $this->currentUserId);

    (new RunAiCampaignControlJob($log->operation, $log->id, $params))->handle();

    $log->refresh();

    expect($log->status)->toBe('completed')
        ->and($log->result)->toContain('Opened campaign')
        ->and(AiCampaign::query()->count())->toBe(1);
});

test('the campaign control job declares a stronghold', function (): void {
    $campaign = aiCampaignControlCampaign();
    Queue::fake();

    $params = ['campaign_id' => $campaign->id, 'planet_id' => $this->currentPlanetId];
    $log = app(RunAiCampaignControlAction::class)->handle(AiCampaignControl::Declare, $params, $this->currentUserId);

    (new RunAiCampaignControlJob($log->operation, $log->id, $params))->handle();

    expect(AiCampaignObjective::query()->count())->toBe(1)
        ->and($log->refresh()->status)->toBe('completed');
});

test('an unknown campaign control is refused', function (): void {
    Queue::fake();

    $this->post(route('ai.campaigns'), ['control' => 'campaign:nuke'])
        ->assertSessionHasErrors('control');

    expect(AiOperationLog::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the campaign panel mirrors the player-facing summary', function (): void {
    $campaign = aiCampaignControlCampaign();

    $panel = app(BuildAiCampaignControlAction::class)->handle();

    expect($panel['campaign'])->not->toBeNull()
        ->and($panel['campaign']['total'])->toBe(0)
        ->and($panel['campaigns'])->not->toBeEmpty()
        ->and($panel['campaigns'][0]['id'])->toBe($campaign->id);
});
