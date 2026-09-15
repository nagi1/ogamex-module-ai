<?php

namespace Modules\AI\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Actions\ExecuteAiIntentAction;
use Modules\AI\Actions\ResolveAiAdmissionAction;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Enums\AiActionReceiptResultKey;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiQueueName;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\Planet;
use Throwable;

class ProcessAiWork implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Bounds a poison work item; retryLease() owns the work-item attempt cap. */
    public int $maxExceptions = 3;

    /**
     * Leave margin below the module's Horizon timeout so a worker is not force-killed
     * mid-decision. The grand run raises both values for hybrid driver contention.
     */
    public int $timeout;

    private const PAYLOAD_PLANET_ID = 'planet_id';

    public function __construct(public int $workItemId)
    {
        $this->timeout = max(1, (int) config('ai.horizon.supervisors.supervisor-ai.timeout', 30) - 5);

        // Deterministic AI work has its own module-owned Horizon lane so AI volume
        // cannot starve the general or fleet lanes.
        $this->onQueue(AiQueueName::Ai->value);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai', 'ai:work', 'ai:work-item:' . $this->workItemId];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('AI work item failed after all queue attempts', [
            'work_item_id' => $this->workItemId,
            'error' => $exception->getMessage(),
        ]);

        $this->scheduleSessionRecovery();
    }

    public function handle(): void
    {
        // A switch that is off stops new decisions without discarding the work: the item stays
        // pending and the first pass after it is switched back on picks it up again.
        if (!app(ResolveAiAdmissionAction::class)->forWorkItem()->allowed) {
            return;
        }

        $leaseToken = (string) Str::uuid();
        // Claim before side effects. Every terminal update checks this token so
        // a slow worker cannot complete work leased again by another worker.
        $workItem = $this->claimDueWork($leaseToken);

        if ($workItem === null) {
            return;
        }

        $lock = Cache::lock('ai:player:' . $workItem->player_id, 300);
        if (!$lock->get()) {
            $this->retryContendedLease($workItem, $leaseToken);

            return;
        }

        try {
            $profile = AiProfile::query()->where('player_id', $workItem->player_id)->where('enabled', true)->first();
            if ($profile === null) {
                $this->completeLease($workItem, $leaseToken);

                return;
            }

            if ($workItem->kind === AiWorkKind::RunSession) {
                $this->runClaimedSession($profile, $workItem, $leaseToken);

                return;
            }

            $this->queueClaimedIntent($profile, $workItem, $leaseToken);
        } catch (Throwable $exception) {
            $this->retryLease($workItem, $leaseToken);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function claimDueWork(string $leaseToken): AiWorkItem|null
    {
        return DB::transaction(function () use ($leaseToken): AiWorkItem|null {
            /** @var AiWorkItem|null $workItem */
            $workItem = AiWorkItem::query()->lockForUpdate()->find($this->workItemId);
            if ($workItem === null || (!$this->acceleratedSession($workItem) && $workItem->due_at->isFuture()) || !$this->isClaimable($workItem)) {
                return null;
            }

            $workItem->update([
                'state' => AiWorkState::Leased,
                'lease_token' => $leaseToken,
                'lease_until' => now()->addMinutes(5),
                'attempts' => $workItem->attempts + 1,
            ]);

            return $workItem->fresh();
        });
    }

    private function acceleratedSession(AiWorkItem $workItem): bool
    {
        return $workItem->kind === AiWorkKind::RunSession
            && (int) config('ai.population.session_interval_seconds', 0) > 0;
    }

    private function isClaimable(AiWorkItem $workItem): bool
    {
        if (in_array($workItem->state, [AiWorkState::Pending, AiWorkState::Retry], true)) {
            return true;
        }

        // A worker killed mid-handle leaves the item Leased; reclaim it once the lease
        // expires so a queue retry or the scheduler can finish the work instead of the
        // item staying stuck forever.
        return $workItem->state === AiWorkState::Leased
            && $workItem->lease_until !== null
            && $workItem->lease_until->isPast();
    }

    private function runClaimedSession(AiProfile $profile, AiWorkItem $workItem, string $leaseToken): void
    {
        app(RunAiSession::class)->handle($profile, $workItem);
        $this->completeLease($workItem, $leaseToken);
    }

    private function queueClaimedIntent(AiProfile $profile, AiWorkItem $workItem, string $leaseToken): void
    {
        // The action cap bounds what a session may touch, not what it may think: at zero the
        // session already decided and scheduled, so this pass closes its lease and acts on
        // nothing.
        if (!app(ResolveAiAdmissionAction::class)->forAction()->allowed) {
            $this->completeLease($workItem, $leaseToken);

            return;
        }

        $receipt = AiActionReceipt::query()->firstOrCreate(
            ['idempotency_key' => $workItem->idempotency_key],
            [
                'player_id' => $workItem->player_id,
                'action_type' => $workItem->kind->actionType(),
                'state' => AiReceiptState::Processing,
            ],
        );
        if (in_array($receipt->state, [AiReceiptState::Accepted, AiReceiptState::Completed, AiReceiptState::Rejected], true)) {
            $this->completeLease($workItem, $leaseToken);

            return;
        }

        $planetId = $this->ownedPlanetIdFor($workItem);
        if ($planetId === 0) {
            $receipt->update(['state' => AiReceiptState::Rejected, 'result' => [AiActionReceiptResultKey::Reason->value => AiQueueActionReason::NoOwnedPlanet->value]]);
            $this->completeLease($workItem, $leaseToken);

            return;
        }

        // An intent that names a body this account does not own is not this account's to act on, and
        // re-planning it onto a different one would silently change what the session decided.
        if (!Planet::query()->whereKey($planetId)->where('user_id', $workItem->player_id)->exists()) {
            $receipt->update(['state' => AiReceiptState::Rejected, 'result' => [AiActionReceiptResultKey::Reason->value => AiQueueActionReason::PlanetNotOwned->value]]);
            $this->completeLease($workItem, $leaseToken);

            return;
        }

        [$result, $decision, $actedPlanetId] = app(ExecuteAiIntentAction::class)->execute($workItem, $planetId);
        if ($result === null) {
            $receipt->update(['state' => AiReceiptState::Rejected, 'result' => [AiActionReceiptResultKey::Reason->value => AiQueueActionReason::NothingQueueable->value]]);
            $this->completeLease($workItem, $leaseToken);

            return;
        }

        $receipt->update([
            'state' => $result->successful ? AiReceiptState::Accepted : AiReceiptState::Rejected,
            'result' => [
                AiActionReceiptResultKey::QueueId->value => $result->queueId,
                AiActionReceiptResultKey::Reason->value => $result->reason,
                AiActionReceiptResultKey::Decision->value => $decision,
                AiActionReceiptResultKey::PlanetId->value => $actedPlanetId,
            ],
        ]);
        $this->completeLease($workItem, $leaseToken);
    }

    private function ownedPlanetIdFor(AiWorkItem $workItem): int
    {
        return (int) ($workItem->payload[self::PAYLOAD_PLANET_ID] ?? Planet::query()->where('user_id', $workItem->player_id)->value('id'));
    }

    private function completeLease(AiWorkItem $workItem, string $leaseToken): void
    {
        AiWorkItem::query()->whereKey($workItem->id)->where('lease_token', $leaseToken)->update([
            'state' => AiWorkState::Completed, 'lease_token' => null, 'lease_until' => null,
        ]);
    }

    private function retryLease(AiWorkItem $workItem, string $leaseToken): void
    {
        $failed = $workItem->attempts >= $this->tries;

        AiWorkItem::query()->whereKey($workItem->id)->where('lease_token', $leaseToken)->update([
            'state' => $failed ? AiWorkState::Failed : AiWorkState::Retry,
            'due_at' => now()->addMinute(),
            'lease_token' => null,
            'lease_until' => null,
        ]);

        if ($failed) {
            $this->scheduleSessionRecovery();
        }
    }

    private function retryContendedLease(AiWorkItem $workItem, string $leaseToken): void
    {
        AiWorkItem::query()->whereKey($workItem->id)->where('lease_token', $leaseToken)->update([
            'state' => AiWorkState::Retry,
            'due_at' => now()->addSeconds(5),
            'attempts' => 0,
            'lease_token' => null,
            'lease_until' => null,
        ]);
    }

    private function scheduleSessionRecovery(): void
    {
        DB::transaction(function (): void {
            $workItem = AiWorkItem::query()
                ->lockForUpdate()
                ->find($this->workItemId);

            if ($workItem === null || $workItem->kind !== AiWorkKind::RunSession || $workItem->state !== AiWorkState::Failed) {
                return;
            }

            $schedule = AiSchedule::query()
                ->where('player_id', $workItem->player_id)
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                return;
            }

            if ($schedule->generation > (int) $workItem->schedule_generation) {
                return;
            }

            $nextGeneration = max($schedule->generation, (int) $workItem->schedule_generation) + 1;
            $nextDueAt = now()->addMinute();

            $schedule->update([
                'next_due_at' => $nextDueAt,
                'generation' => $nextGeneration,
            ]);

            AiWorkItem::query()->firstOrCreate(
                ['idempotency_key' => 'session:' . $workItem->player_id . ':' . $nextGeneration],
                [
                    'player_id' => $workItem->player_id,
                    'kind' => AiWorkKind::RunSession,
                    'due_at' => $nextDueAt,
                    'schedule_generation' => $nextGeneration,
                    'state' => AiWorkState::Pending,
                ],
            );
        });
    }
}
