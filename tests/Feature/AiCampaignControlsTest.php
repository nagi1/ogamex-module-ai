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

test('declaring a stronghold queues an audited run', function (): void {
    $campaign = aiCampaignControlCampaign();
    Queue::fake();

    $this->post(route('ai.campaigns'), [
        'control' => 'campaign:declare',
        'campaign_id' => (string) $campaign->id,
        'planet_id' => (string) $this->currentPlanetId,
    ])->assertRedirect(route('ai.index', ['tab' => 'campaigns']));

    expect(AiOperationLog::query()->sole()->operation)->toBe('campaign:declare');
    Queue::assertPushed(RunAiCampaignControlJob::class);
});

test('the no-input controls queue an audited run', function (): void {
    Queue::fake();

    foreach (['campaign:advance', 'campaign:apply-alliances', 'campaign:bond-alliances'] as $control) {
        $this->post(route('ai.campaigns'), ['control' => $control])
            ->assertRedirect(route('ai.index', ['tab' => 'campaigns']));
    }

    expect(AiOperationLog::query()->count())->toBe(3);
    Queue::assertPushed(RunAiCampaignControlJob::class, 3);
});

test('the campaign control job records a failure', function (): void {
    Queue::fake();

    $log = app(RunAiCampaignControlAction::class)->handle(AiCampaignControl::Advance, [], $this->currentUserId);

    (new RunAiCampaignControlJob($log->operation, $log->id, []))->failed(new RuntimeException('boom'));

    $log->refresh();

    expect($log->status)->toBe('failed')
        ->and($log->result)->toBe('boom');
});

test('the campaign control job refuses an open that ends before it starts', function (): void {
    Queue::fake();

    $params = ['starts_at' => '2026-09-29T12:00', 'ends_at' => '2026-09-22T12:00'];
    $log = app(RunAiCampaignControlAction::class)->handle(AiCampaignControl::Open, $params, $this->currentUserId);

    expect(fn () => (new RunAiCampaignControlJob($log->operation, $log->id, $params))->handle())
        ->toThrow(InvalidArgumentException::class, 'end after it starts');
});

test('the campaign control job refuses a declare on a missing campaign or planet', function (): void {
    Queue::fake();

    $params = ['campaign_id' => 999999, 'planet_id' => 999999];
    $log = app(RunAiCampaignControlAction::class)->handle(AiCampaignControl::Declare, $params, $this->currentUserId);

    expect(fn () => (new RunAiCampaignControlJob($log->operation, $log->id, $params))->handle())
        ->toThrow(InvalidArgumentException::class, 'no longer exists');
});

test('the campaign control job runs a command and records the result', function (): void {
    Queue::fake();

    foreach ([AiCampaignControl::Advance, AiCampaignControl::ApplyAlliances, AiCampaignControl::BondAlliances] as $control) {
        $log = app(RunAiCampaignControlAction::class)->handle($control, [], $this->currentUserId);

        (new RunAiCampaignControlJob($log->operation, $log->id, []))->handle();

        expect($log->refresh()->status)->toBe('completed');
    }
});

test('the campaign control job carries the lane tag', function (): void {
    expect((new RunAiCampaignControlJob('campaign:advance', 1, []))->tags())->toContain('ai:campaign');
});
