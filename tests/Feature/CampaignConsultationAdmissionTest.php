<?php

use Modules\AI\Actions\ResolveCampaignConsultationAdmissionAction;
use Modules\AI\Enums\AiCampaignConsultationMode;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Models\AiOperabilitySwitch;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The consultation admission gate is fail-closed: off refuses before any provider work,
 * the staff switch overrides an enabled lane, and a trigger the operator removed is refused
 * with its reason recorded. Observe and advice are the only allowed states.
 */
function admit(AiCampaignConsultationTrigger $trigger): Modules\AI\Domain\Operability\CampaignConsultationAdmission
{
    return app(ResolveCampaignConsultationAdmissionAction::class)->forConsultation($trigger);
}

test('the lane is off by default and refuses every trigger', function (): void {
    $admission = admit(AiCampaignConsultationTrigger::FleetLoss);

    expect($admission->allowed)->toBeFalse()
        ->and($admission->mode)->toBe(AiCampaignConsultationMode::Off)
        ->and($admission->stopReason)->toBe(AiStopReason::ConsultationDisabled);
});

test('the staff switch refuses a consultation even when the lane is enabled', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);
    AiOperabilitySwitch::create(['enabled' => false, 'reason' => 'operator pause', 'changed_at' => now()]);

    $admission = admit(AiCampaignConsultationTrigger::FleetLoss);

    expect($admission->allowed)->toBeFalse()
        ->and($admission->stopReason)->toBe(AiStopReason::StaffSwitch);
});

test('a trigger the operator removed from the allowlist is refused', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);
    config(['ai.campaign-consultation.allowed_triggers' => ['new_phase']]);

    $admission = admit(AiCampaignConsultationTrigger::FleetLoss);

    expect($admission->allowed)->toBeFalse()
        ->and($admission->stopReason)->toBe(AiStopReason::ConsultationTriggerDisallowed);
});

test('an allowed trigger admits in observe mode', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);

    $admission = admit(AiCampaignConsultationTrigger::NewPhase);

    expect($admission->allowed)->toBeTrue()
        ->and($admission->mode)->toBe(AiCampaignConsultationMode::Observe)
        ->and($admission->stopReason)->toBeNull();
});

test('an allowed trigger admits in advice mode', function (): void {
    config(['ai.campaign-consultation.mode' => 'advice']);

    $admission = admit(AiCampaignConsultationTrigger::ContestedObjective);

    expect($admission->allowed)->toBeTrue()
        ->and($admission->mode)->toBe(AiCampaignConsultationMode::Advice);
});

test('an unknown mode string fails closed to off', function (): void {
    config(['ai.campaign-consultation.mode' => 'bogus']);

    $admission = admit(AiCampaignConsultationTrigger::FleetLoss);

    expect($admission->allowed)->toBeFalse()
        ->and($admission->mode)->toBe(AiCampaignConsultationMode::Off)
        ->and($admission->stopReason)->toBe(AiStopReason::ConsultationDisabled);
});
