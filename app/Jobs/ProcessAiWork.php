<?php

namespace Modules\AI\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AI\Actions\ResolveAiAdmissionAction;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Enums\AiActionReceiptResultKey;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiQueueName;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\Planet;
use Throwable;

class ProcessAiWork implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Bounds a poison work item so repeated decision exceptions cannot consume the
     * whole retry budget; retryLease() owns the work-item attempt cap.
     */
    public int $maxExceptions = 3;

    /**
     * Below the supervisor timeout in the host's config/horizon.php so Horizon never
     * force-kills an auto-balancing worker mid-decision, and below the redis
     * retry_after so the job is never handed to a second worker.
     */
    public int $timeout = 25;

    private const PAYLOAD_PLANET_ID = 'planet_id';

    public function __construct(public int $workItemId)
    {
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
        // The work item is reclaimed by its expired lease in claimDueWork() rather than
        // force-failed here, so a hard worker kill cannot clobber a newer lease.
        Log::error('AI work item failed after all queue attempts; awaiting lease reclaim', [
            'work_item_id' => $this->workItemId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(BuildFirstBuilding $buildFirstBuilding): void
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
            $this->retryLease($workItem, $leaseToken);

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

            $this->queueClaimedBuilding($profile, $workItem, $buildFirstBuilding, $leaseToken);
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
            if ($workItem === null || $workItem->due_at->isFuture() || !$this->isClaimable($workItem)) {
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

    private function queueClaimedBuilding(AiProfile $profile, AiWorkItem $workItem, BuildFirstBuilding $buildFirstBuilding, string $leaseToken): void
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
            ['player_id' => $workItem->player_id, 'action_type' => AiActionType::QueueBuilding, 'state' => AiReceiptState::Processing],
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

        $choice = $buildFirstBuilding->choose($profile);
        $result = app(QueueAiBuilding::class)->handle($workItem->player_id, $planetId, $choice['building_id']);
        $receipt->update([
            'state' => $result->successful ? AiReceiptState::Accepted : AiReceiptState::Rejected,
            'result' => [
                AiActionReceiptResultKey::QueueId->value => $result->queueId,
                AiActionReceiptResultKey::Reason->value => $result->reason,
                AiActionReceiptResultKey::Decision->value => $choice,
                AiActionReceiptResultKey::PlanetId->value => $planetId,
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
        AiWorkItem::query()->whereKey($workItem->id)->where('lease_token', $leaseToken)->update([
            'state' => $workItem->attempts >= $this->tries ? AiWorkState::Failed : AiWorkState::Retry,
            'due_at' => now()->addMinute(),
            'lease_token' => null,
            'lease_until' => null,
        ]);
    }
}
