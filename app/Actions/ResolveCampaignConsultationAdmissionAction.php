<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Operability\CampaignConsultationAdmission;
use Modules\AI\Enums\AiCampaignConsultationMode;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Models\AiOperabilitySwitch;

/**
 * The fail-closed admission gate the campaign consultation lane must pass before any
 * provider work.
 *
 * Off is checked first and never resolves the SDK configuration or contacts a provider, so
 * the default installation cannot send campaign facts anywhere. The staff switch and the
 * operator's trigger allowlist follow; the daily ceilings and per-trigger cooldown are
 * settled at the receipt path where the usage ledger lives, so this action owns the policy
 * questions that have no ledger dependency and the receipt path owns the ones that do.
 */
class ResolveCampaignConsultationAdmissionAction
{
    public function forConsultation(AiCampaignConsultationTrigger $trigger): CampaignConsultationAdmission
    {
        $mode = AiCampaignConsultationMode::tryFrom((string) config('ai.campaign-consultation.mode', AiCampaignConsultationMode::Off->value))
            ?? AiCampaignConsultationMode::Off;

        if ($mode === AiCampaignConsultationMode::Off) {
            return $this->stopped($mode, AiStopReason::ConsultationDisabled, ['mode' => $mode->value]);
        }

        if (!$this->staffSwitchAllowsConsultation()) {
            return $this->stopped($mode, AiStopReason::StaffSwitch, ['pass' => 'consultation']);
        }

        /** @var array<int, string> $allowed */
        $allowed = config('ai.campaign-consultation.allowed_triggers', []);

        if (!in_array($trigger->value, $allowed, true)) {
            return $this->stopped($mode, AiStopReason::ConsultationTriggerDisallowed, ['trigger' => $trigger->value]);
        }

        return $this->allowed($mode);
    }

    /**
     * ponytail: mirrors the work switch read rather than extracting a shared reader yet;
     * extract one only when a third consumer appears.
     */
    private function staffSwitchAllowsConsultation(): bool
    {
        $latest = AiOperabilitySwitch::query()->orderByDesc('id')->first();

        return $latest->enabled ?? true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stopped(AiCampaignConsultationMode $mode, AiStopReason $reason, array $context): CampaignConsultationAdmission
    {
        app(RecordAiStopReasonAction::class)->handle($reason, $context);

        return $this->admission(false, $mode, $reason, $context);
    }

    private function allowed(AiCampaignConsultationMode $mode): CampaignConsultationAdmission
    {
        return $this->admission(true, $mode, null, []);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function admission(bool $allowed, AiCampaignConsultationMode $mode, AiStopReason|null $reason, array $context): CampaignConsultationAdmission
    {
        return app()->makeWith(CampaignConsultationAdmission::class, [
            'allowed' => $allowed,
            'mode' => $mode,
            'stopReason' => $reason,
            'context' => $context,
        ]);
    }
}
