<?php

namespace Modules\AI\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\Planet;
use OGame\Services\ModulePlayerActionService;
use Throwable;

class ProcessAiWork implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    private const PAYLOAD_PLANET_ID = 'planet_id';

    public function __construct(public int $workItemId)
    {
    }

    public function handle(BuildFirstBuilding $buildFirstBuilding, ModulePlayerActionService $modulePlayerActionService): void
    {
        $leaseToken = (string) Str::uuid();
        $workItem = DB::transaction(function () use ($leaseToken): AiWorkItem|null {
            $workItem = AiWorkItem::query()->lockForUpdate()->find($this->workItemId);
            if ($workItem === null || !in_array($workItem->state, [AiWorkState::Pending, AiWorkState::Retry], true) || $workItem->due_at->isFuture()) {
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

            $receipt = AiActionReceipt::firstOrCreate(
                ['idempotency_key' => $workItem->idempotency_key],
                ['player_id' => $workItem->player_id, 'action_type' => AiActionType::QueueBuilding, 'state' => AiReceiptState::Processing],
            );
            if (in_array($receipt->state, [AiReceiptState::Completed, AiReceiptState::Rejected], true)) {
                $this->completeLease($workItem, $leaseToken);

                return;
            }

            $planetId = (int) ($workItem->payload[self::PAYLOAD_PLANET_ID] ?? Planet::query()->where('user_id', $workItem->player_id)->value('id'));
            if ($planetId === 0) {
                $receipt->update(['state' => AiReceiptState::Rejected, 'result' => ['reason' => 'no_owned_planet']]);
                $this->completeLease($workItem, $leaseToken);

                return;
            }

            $choice = $buildFirstBuilding->choose($profile);
            $result = $modulePlayerActionService->queueBuilding($workItem->player_id, $planetId, $choice['building_id']);
            $receipt->update([
                'state' => $result->successful ? AiReceiptState::Completed : AiReceiptState::Rejected,
                'result' => ['queue_id' => $result->queueId, 'reason' => $result->reason, 'decision' => $choice],
            ]);
            $this->completeLease($workItem, $leaseToken);
        } catch (Throwable $exception) {
            $this->retryLease($workItem, $leaseToken);

            throw $exception;
        } finally {
            $lock->release();
        }
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
