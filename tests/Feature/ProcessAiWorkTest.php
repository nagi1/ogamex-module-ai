<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\BuildingQueue;
use OGame\Models\Resources;
use OGame\Services\ModulePlayerActionService;
use Tests\IsolatedAccountTestCase;

class ProcessAiWorkTest extends IsolatedAccountTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('ai_profiles')) {
            Artisan::call('migrate', [
                '--path' => dirname(__DIR__, 2) . '/database/migrations',
                '--realpath' => true,
                '--force' => true,
            ]);
        }
    }

    public function test_due_work_is_idempotent(): void
    {
        $this->planetAddResources(new Resources(1_000_000, 1_000_000, 1_000_000));
        AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42]);
        $work = AiWorkItem::create(['player_id' => $this->currentUserId, 'kind' => AiWorkKind::BuildFirstBuilding, 'due_at' => now(), 'idempotency_key' => 'test-work-' . $this->currentUserId, 'state' => AiWorkState::Pending]);
        $job = new ProcessAiWork($work->id);
        $decision = new BuildFirstBuilding(new SeededBuildingScoringPolicy());
        $actions = resolve(ModulePlayerActionService::class);

        $job->handle($decision, $actions);
        $work->update(['state' => AiWorkState::Pending]);
        $job->handle($decision, $actions);

        $this->assertSame(1, BuildingQueue::query()->where('planet_id', $this->currentPlanetId)->count());
        $this->assertSame(1, AiActionReceipt::query()->where('player_id', $this->currentUserId)->count());
    }

    public function test_invalid_owned_planet_is_recorded_as_rejected(): void
    {
        AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 42]);
        $work = AiWorkItem::create(['player_id' => $this->currentUserId, 'kind' => AiWorkKind::BuildFirstBuilding, 'due_at' => now(), 'payload' => ['planet_id' => PHP_INT_MAX], 'idempotency_key' => 'rejected-work-' . $this->currentUserId, 'state' => AiWorkState::Pending]);

        (new ProcessAiWork($work->id))->handle(new BuildFirstBuilding(new SeededBuildingScoringPolicy()), resolve(ModulePlayerActionService::class));

        $this->assertDatabaseHas('ai_action_receipts', ['idempotency_key' => $work->idempotency_key, 'state' => AiReceiptState::Rejected->value]);
    }

    public function test_lock_contention_retries_work_without_an_action(): void
    {
        $work = AiWorkItem::create(['player_id' => $this->currentUserId, 'kind' => AiWorkKind::BuildFirstBuilding, 'due_at' => now(), 'idempotency_key' => 'locked-work-' . $this->currentUserId, 'state' => AiWorkState::Pending]);
        $lock = Cache::lock('ai:player:' . $this->currentUserId, 300);
        $lock->get();

        try {
            (new ProcessAiWork($work->id))->handle(new BuildFirstBuilding(new SeededBuildingScoringPolicy()), resolve(ModulePlayerActionService::class));
        } finally {
            $lock->release();
        }

        $this->assertSame(AiWorkState::Retry, $work->fresh()->state);
        $this->assertSame(0, AiActionReceipt::query()->where('idempotency_key', $work->idempotency_key)->count());
    }
}
