<?php

use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Domain\Decision\BuildingScoringPolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\CasualPolicy;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TraderPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
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
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(RunAiSession::class, RunAiSessionAction::class);
    $this->app->bind(AiClock::class, SystemAiClock::class);
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    $this->app->bind(BuildingScoringPolicy::class, SeededBuildingScoringPolicy::class);
    $this->app->tag([MinerPolicy::class, TurtlePolicy::class, FleeterPolicy::class, TraderPolicy::class, CasualPolicy::class], ArchetypePolicy::class);
    $this->app->singleton(ArchetypePolicyResolver::class, fn ($app): ArchetypePolicyRegistry => $app->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $app->tagged(ArchetypePolicy::class),
    ]));
});

test('session work records a redacted trace and schedules one successor through container actions', function (): void {
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42]);
    $work = AiWorkItem::create(['player_id' => $this->currentUserId, 'kind' => AiWorkKind::RunSession, 'due_at' => now(), 'idempotency_key' => 'session-start-' . $this->currentUserId, 'state' => AiWorkState::Pending]);

    $this->app->makeWith(ProcessAiWork::class, ['workItemId' => $work->id])->handle($this->app->make(BuildFirstBuilding::class));

    expect($work->fresh()?->state)->toBe(AiWorkState::Completed)
        ->and(AiDecisionTrace::query()->where('player_id', $this->currentUserId)->count())->toBe(1)
        ->and(AiSchedule::query()->where('player_id', $this->currentUserId)->first())->not->toBeNull()
        ->and(AiWorkItem::query()->where('player_id', $this->currentUserId)->where('kind', AiWorkKind::RunSession->value)->where('idempotency_key', '!=', $work->idempotency_key)->count())->toBe(1)
        ->and(app(RunAiSession::class))->toBeInstanceOf(RunAiSessionAction::class);
});
