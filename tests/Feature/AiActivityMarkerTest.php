<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\BuildingQueue;
use OGame\Models\Resources;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The host's inactivity detector and the galaxy's activity marker both read the
 * one `users.time` stamp, and it moves because real work touched the planet --
 * `PlayerGameStateService::advance()` is the host's own page-load path, and the
 * module reaches it only through the host action it delegates to.
 *
 * So the marker must move when the account did something and must not move when
 * it did nothing. A module that refreshed it to look alive would be writing a
 * keep-alive ping, which is the tell this rule exists to prevent.
 */
test('a session that decides nothing does not move the activity marker', function (): void {
    config(['ai.cognition.conversation.enabled' => false]);
    $profile = aiMarkerProfile($this->currentUserId);
    $this->travel(2)->minutes();
    $before = User::query()->whereKey($this->currentUserId)->value('time');

    $session = aiMarkerSession($profile, 'idle');
    app()->makeWith(ProcessAiWork::class, ['workItemId' => $session->id])->handle();

    // No resources, so the account can queue nothing and its trace says so.
    expect(BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->count())->toBe(0)
        ->and(User::query()->whereKey($this->currentUserId)->value('time'))->toBe($before);
});

test('queued work moves the activity marker through the host', function (): void {
    config(['ai.cognition.conversation.enabled' => false]);
    $profile = aiMarkerProfile($this->currentUserId);
    $this->planetAddResources(app()->makeWith(Resources::class, ['metal' => 1_000_000, 'crystal' => 1_000_000, 'deuterium' => 1_000_000]));
    $this->travel(2)->minutes();
    $before = User::query()->whereKey($this->currentUserId)->value('time');

    $session = aiMarkerSession($profile, 'work');
    app()->makeWith(ProcessAiWork::class, ['workItemId' => $session->id])->handle();

    $intent = AiWorkItem::query()
        ->where('player_id', $profile->player_id)
        ->where('kind', AiWorkKind::BuildFirstBuilding)
        ->firstOrFail();

    app()->makeWith(ProcessAiWork::class, ['workItemId' => $intent->id])->handle();

    expect(BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->count())->toBe(1)
        ->and(User::query()->whereKey($this->currentUserId)->value('time'))->toBeGreaterThan($before);
});

function aiMarkerProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 5_100 + $playerId,
    ]);
}

function aiMarkerSession(AiProfile $profile, string $suffix): AiWorkItem
{
    return AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'marker-session:' . $profile->player_id . ':' . $suffix,
        'state' => AiWorkState::Pending,
    ]);
}
