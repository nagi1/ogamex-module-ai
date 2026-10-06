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
use Modules\AI\Domain\Decision\RecentRefusals;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiActionReceiptResultKey;
use Modules\AI\Enums\AiActionType;
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
            if ($workItem === null || !$this->isClaimable($workItem) || !$this->dueForAccount($workItem)) {
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
        if ($workItem->kind !== AiWorkKind::RunSession || (int) config('ai.population.session_interval_seconds', 0) <= 0) {
            return false;
        }

        // Acceleration shortens the waits, not the night: a dark-period session keeps its due time.
        $profile = $this->workProfile($workItem);

        return $profile === null || app(SessionPlanner::class)->isAwake($profile, now()->toImmutable());
    }

    /**
     * Whether the account's own clock has come for this item.
     *
     * An accelerated session is claimable the moment its account is awake (that is what shortens the
     * waits), and any item is claimable once its own due time has passed. A session is the exception:
     * the one a session leaves behind is due seconds later, so a worker that is minutes behind finds
     * every session in the cohort overdue, and an overdue session used to be claimable at any hour --
     * which put the whole population online through its own night (AUTH_UPTIME). A session therefore
     * keeps the hour it was scheduled for: only the night session the routine placed in the dark
     * period itself, the reaction wake to an attack landing then, is run at night.
     */
    private function dueForAccount(AiWorkItem $workItem): bool
    {
        if ($workItem->due_at->isFuture()) {
            return $this->acceleratedSession($workItem);
        }

        return $workItem->kind !== AiWorkKind::RunSession || $this->sessionKeepsItsOwnHour($workItem);
    }

    /**
     * A session scheduled inside the account's waking window waits for its waking day however late the
     * worker is; one scheduled inside the dark period is a wake the routine meant to take.
     */
    private function sessionKeepsItsOwnHour(AiWorkItem $workItem): bool
    {
        $profile = $this->workProfile($workItem);
        if ($profile === null) {
            return true;
        }

        $planner = app(SessionPlanner::class);

        return $planner->isAwake($profile, now()->toImmutable()) || !$planner->isAwake($profile, $workItem->due_at->toImmutable());
    }

    private function workProfile(AiWorkItem $workItem): AiProfile|null
    {
        return AiProfile::query()->where('player_id', $workItem->player_id)->first();
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

        // A decision is written before it flies and the worker may run it hours later, so a refusal the
        // gate raised in between is one no planner could have read. The account does not ask again what it
        // was already told: an intent naming a refused body or target is dropped, and the refusal stays
        // the one receipt instead of one per queued decision (DISPATCH_REFUSALS).
        if ($this->repeatsRefusal($workItem)) {
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
                AiActionReceiptResultKey::Lane->value => $workItem->kind->value,
            ],
        ]);
        $this->completeLease($workItem, $leaseToken);
    }

    private function ownedPlanetIdFor(AiWorkItem $workItem): int
    {
        return (int) ($workItem->payload[self::PAYLOAD_PLANET_ID] ?? Planet::query()->where('user_id', $workItem->player_id)->value('id'));
    }

    /**
     * Whether this intent would repeat an answer the account already holds: the body its payload names as
     * the origin, or the coordinates it names as the target, is one the gate refused inside the cooling.
     */
    private function repeatsRefusal(AiWorkItem $workItem): bool
    {
        // A raid wave schedules raids; it flies nothing itself, so the body its payload carries is only the
        // account's first planet rather than an origin.
        if ($workItem->kind->actionType() !== AiActionType::DispatchFleet || $workItem->kind === AiWorkKind::RaidWave) {
            return false;
        }

        $payload = $workItem->payload ?? [];

        return app(RecentRefusals::class)->cools(
            $workItem->player_id,
            $workItem->kind,
            (int) ($payload[self::PAYLOAD_PLANET_ID] ?? $payload['source_planet_id'] ?? 0),
            (int) ($payload['target_galaxy'] ?? 0),
            (int) ($payload['target_system'] ?? 0),
            (int) ($payload['target_position'] ?? 0),
        );
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

            // A failed session is retried a minute on only while the account is awake: a retry in the
            // dark period would put the account on at an hour it sleeps (AUTH_UPTIME), so it takes the
            // routine's next wake instead. An accelerated cohort keeps the minute: a failure there would
            // otherwise park the account for hours, and failures are rare enough not to fill the night.
            $profile = AiProfile::query()->where('player_id', $workItem->player_id)->first();
            if ((int) config('ai.population.session_interval_seconds', 0) <= 0 && $profile !== null && !app(SessionPlanner::class)->isAwake($profile, $nextDueAt->toImmutable())) {
                $nextDueAt = app(SessionPlanner::class)->plan($profile, now()->toImmutable(), $schedule->generation)->nextDueAt;
            }

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
