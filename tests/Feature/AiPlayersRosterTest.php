<?php

use Illuminate\Support\Str;
use Modules\AI\Actions\BuildAiPlayerRosterAction;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';

uses(AiQueueModuleTestCase::class);

beforeEach(function (): void {
    $this->artisan('ogamex:admin:assign-role', ['username' => $this->currentUsername]);
});

function aiRosterProfile(int $playerId, bool $enabled = true): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => $playerId,
        'enabled' => $enabled,
    ]);
}

test('the roster lists enabled and stopped accounts with their usernames', function (): void {
    $stopped = $this->createUser();
    aiRosterProfile($this->currentUserId);
    aiRosterProfile($stopped->id, false);

    $roster = app(BuildAiPlayerRosterAction::class)->handle(7);
    $rows = collect($roster['rows']);

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('player_id', $stopped->id)['enabled'])->toBeFalse()
        ->and($rows->firstWhere('player_id', $stopped->id)['username'])->toBe($stopped->username)
        ->and($rows->firstWhere('player_id', $this->currentUserId)['username'])->toBe($this->currentUsername);
});

test('the roster filters by state and by search', function (): void {
    $stopped = $this->createUser();
    aiRosterProfile($this->currentUserId);
    aiRosterProfile($stopped->id, false);

    $stoppedOnly = app(BuildAiPlayerRosterAction::class)->handle(7, '', 'stopped');

    expect(collect($stoppedOnly['rows'])->pluck('player_id')->all())->toBe([$stopped->id]);

    $searched = app(BuildAiPlayerRosterAction::class)->handle(7, (string) $this->currentUserId);

    expect(collect($searched['rows'])->pluck('player_id')->all())->toBe([$this->currentUserId]);
});

test('the roster renders the view, view-as and stop controls', function (): void {
    aiRosterProfile($this->currentUserId);

    $content = $this->get('/admin/ai?tab=players')->getContent();

    expect($content)->toContain('View as')
        ->toContain(route('admin.developershortcuts.impersonate'))
        ->toContain('Stop')
        ->toContain('View');
});

test('stopping an account from the roster records who, why and when', function (): void {
    aiRosterProfile($this->currentUserId);

    $this->post(route('ai.account.switch'), [
        'player_id' => (string) $this->currentUserId,
        'enabled' => '0',
        'reason' => 'Stopped from the Players roster.',
    ])->assertRedirect(route('ai.index', ['tab' => 'players']));

    $profile = AiProfile::query()->where('player_id', $this->currentUserId)->sole();
    $record = $profile->settings['account_switch'];

    expect($profile->enabled)->toBeFalse()
        ->and($record['reason'])->toBe('Stopped from the Players roster.')
        ->and($record['actor_player_id'])->toBe($this->currentUserId)
        ->and($record['changed_at'])->not->toBeNull();
});

test('the roster falls back to the player id when no user row exists', function (): void {
    aiRosterProfile(999999);

    $roster = app(BuildAiPlayerRosterAction::class)->handle(7);

    expect($roster['rows'][0]['username'])->toBe('999999');
});

test('the roster filters to accounts with alerts only', function (): void {
    aiRosterProfile($this->currentUserId);
    $quiet = $this->createUser();
    aiRosterProfile($quiet->id);

    AiActionReceipt::create(['player_id' => $quiet->id, 'idempotency_key' => 'quiet', 'action_type' => AiActionType::QueueBuilding, 'state' => AiReceiptState::Completed, 'result' => []]);

    $alertsOnly = app(BuildAiPlayerRosterAction::class)->handle(7, '', 'all', true);

    expect(collect($alertsOnly['rows'])->pluck('player_id')->all())->toBe([$this->currentUserId]);
});

test('the roster flags stuck work', function (): void {
    aiRosterProfile($this->currentUserId);

    AiWorkItem::create([
        'player_id' => $this->currentUserId,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now()->subHour(),
        'attempts' => 0,
        'idempotency_key' => 'stuck-' . Str::random(8),
        'schedule_generation' => 1,
        'state' => AiWorkState::Leased,
        'lease_token' => 'lease',
        'lease_until' => now()->subMinute(),
    ]);

    $roster = app(BuildAiPlayerRosterAction::class)->handle(7);

    expect($roster['rows'][0]['alerts'])->toContain('stuck');
});
