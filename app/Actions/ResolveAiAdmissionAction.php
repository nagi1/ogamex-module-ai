<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Operability\AiAdmission;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiOperabilitySwitch;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;

/**
 * Decides how much work the module may start right now, and records why it stopped.
 *
 * Every cap lives here rather than at its call sites. The scheduler, the session job and the
 * action path all ask the same question, and a limit one caller enforced while another forgot
 * would read in a pilot report as an idle population rather than a capped one. The refusal is
 * recorded together with the decision, because a stop nobody wrote down looks the same as no
 * stop at all.
 *
 * The size caps are checked against what the module can see rather than what it enforces by
 * deleting anything: exceeding the profile cap stops new work and leaves the decision of which
 * accounts to remove to the operator.
 */
class ResolveAiAdmissionAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    /**
     * @param int $requestedLimit the batch the caller would like to dispatch
     */
    public function forDispatch(int $requestedLimit): AiAdmission
    {
        if (!$this->staffSwitchAllowsWork()) {
            return $this->stopped(AiStopReason::StaffSwitch, ['pass' => 'dispatch']);
        }

        $profileCap = max(0, (int) config('ai.population.profile_cap', 0));
        $enabledProfiles = AiProfile::query()->where('enabled', true)->count();

        if ($profileCap > 0 && $enabledProfiles > $profileCap) {
            return $this->stopped(AiStopReason::ProfileCap, ['profiles' => $enabledProfiles, 'cap' => $profileCap]);
        }

        $sessionCap = max(0, (int) config('ai.population.active_session_cap', 0));
        $activeSessions = $this->activeSessionCount();

        if ($sessionCap > 0 && $activeSessions >= $sessionCap) {
            return $this->stopped(AiStopReason::ActiveSessionCap, ['active_sessions' => $activeSessions, 'cap' => $sessionCap]);
        }

        $batchSize = max(1, (int) config('ai.population.dispatch_batch_size', 100));

        if ($batchSize < $requestedLimit) {
            return $this->limited($batchSize, AiStopReason::DispatchLimit, ['requested' => $requestedLimit, 'cap' => $batchSize]);
        }

        return $this->allowed($requestedLimit);
    }

    /**
     * Whether a claimed session may run. The lease is taken before this question is asked, so a
     * refusal leaves the work item pending for the first pass after the switch returns.
     */
    public function forWorkItem(): AiAdmission
    {
        if (!$this->staffSwitchAllowsWork()) {
            return $this->stopped(AiStopReason::StaffSwitch, ['pass' => 'work_item']);
        }

        return $this->allowed(1);
    }

    /**
     * Whether a session may queue a game action. The cap applies to the action, not to the
     * account: at zero an AI still decides, records its intent and schedules its next session,
     * which is the setting a staff member wants while investigating something.
     */
    public function forAction(): AiAdmission
    {
        if (!$this->staffSwitchAllowsWork()) {
            return $this->stopped(AiStopReason::StaffSwitch, ['pass' => 'action']);
        }

        $actionCap = (int) config('ai.population.session_action_cap', 1);

        if ($actionCap < 1) {
            return $this->stopped(AiStopReason::SessionActionCap, ['cap' => $actionCap]);
        }

        return $this->allowed($actionCap);
    }

    /**
     * Work in flight is what the active-session cap counts: a work item that is leased now is a
     * session a worker is running, and one whose lease expired is work the scheduler will retry
     * rather than work that is running.
     */
    private function activeSessionCount(): int
    {
        return AiWorkItem::query()
            ->where('state', AiWorkState::Leased)
            ->where('lease_until', '>', $this->clock->now())
            ->count();
    }

    /**
     * An installation that never recorded a switch decision runs, so installing the module does
     * not require a first write before the population works.
     */
    private function staffSwitchAllowsWork(): bool
    {
        $latest = AiOperabilitySwitch::query()->orderByDesc('id')->first();

        return $latest?->enabled ?? true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stopped(AiStopReason $reason, array $context): AiAdmission
    {
        app(RecordAiStopReasonAction::class)->handle($reason, $context);

        return $this->admission(false, 0, $reason, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function limited(int $limit, AiStopReason $reason, array $context): AiAdmission
    {
        app(RecordAiStopReasonAction::class)->handle($reason, $context);

        return $this->admission(true, $limit, $reason, $context);
    }

    private function allowed(int $limit): AiAdmission
    {
        return $this->admission(true, $limit, null, []);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function admission(bool $allowed, int $limit, AiStopReason|null $reason, array $context): AiAdmission
    {
        return app()->makeWith(AiAdmission::class, [
            'allowed' => $allowed,
            'limit' => $limit,
            'stopReason' => $reason,
            'context' => $context,
        ]);
    }
}
