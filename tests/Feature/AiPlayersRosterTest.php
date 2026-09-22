<?php

use Modules\AI\Actions\BuildAiPlayerRosterAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
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
