<?php

use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Support\SystemAiClock;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(RunAiSession::class, RunAiSessionAction::class);
    $this->app->bind(AiClock::class, SystemAiClock::class);
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    $this->app->tag([MinerPolicy::class, TurtlePolicy::class, FleeterPolicy::class], ArchetypePolicy::class);
    $this->app->singleton(ArchetypePolicyResolver::class, fn ($app): ArchetypePolicyRegistry => $app->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $app->tagged(ArchetypePolicy::class),
    ]));
});

test('session work records a redacted trace and schedules one successor through container actions', function (): void {
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42]);
    $work = AiWorkItem::create(['player_id' => $this->currentUserId, 'kind' => AiWorkKind::RunSession, 'due_at' => now(), 'idempotency_key' => 'session-start-' . $this->currentUserId, 'state' => AiWorkState::Pending]);

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    expect($work->fresh()?->state)->toBe(AiWorkState::Completed)
        ->and(AiDecisionTrace::query()->where('player_id', $this->currentUserId)->count())->toBe(1)
        ->and(AiSchedule::query()->where('player_id', $this->currentUserId)->first())->not->toBeNull()
        ->and(AiWorkItem::query()->where('player_id', $this->currentUserId)->where('kind', AiWorkKind::RunSession->value)->where('idempotency_key', '!=', $work->idempotency_key)->count())->toBe(1)
        ->and(app(RunAiSession::class))->toBeInstanceOf(RunAiSessionAction::class);
});

test('a session drains the build queue of every planet, not only the current one', function (): void {
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42]);

    // A second planet as a plain factory row, not the host's colonisation path: that path commits
    // its own transaction and would leak the body into the shared serial-coverage database, where
    // it collides with tests that derive target coordinates from the homeworld.
    $colony = Planet::factory()->create([
        'user_id' => $this->currentUserId,
        'galaxy' => 2,
        'system' => 400,
        'planet' => 1,
        'time_last_update' => now()->timestamp,
    ]);

    // A finished metal mine on the non-current planet: a page load there would apply it, and the
    // session must too, or a colony never builds anything.
    $item = new BuildingQueue();
    $item->planet_id = $colony->id;
    $item->object_id = ObjectService::getObjectByMachineName('metal_mine')->id;
    $item->object_level_target = 1;
    $item->is_downgrade = false;
    $item->time_duration = 0;
    $item->time_start = now()->timestamp - 120;
    $item->time_end = now()->timestamp - 60;
    $item->building = 1;
    $item->processed = 0;
    $item->canceled = 0;
    $item->save();

    $work = AiWorkItem::create(['player_id' => $this->currentUserId, 'kind' => AiWorkKind::RunSession, 'due_at' => now(), 'idempotency_key' => 'session-colony-' . $this->currentUserId, 'state' => AiWorkState::Pending]);
    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle();

    expect(Planet::query()->findOrFail($colony->id)->metal_mine)->toBe(1);
});
