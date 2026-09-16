<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Actions\RequestCampaignConsultationAction;
use Modules\AI\Contracts\CampaignConsultationGateway;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRecommendation;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRequest;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiCampaignConsultationRisk;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignConsultationReceipt;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The consultation lane is fail-closed end to end: off never touches a gateway, a completed
 * recommendation may only name a supplied candidate and cite supplied evidence, the daily
 * ceiling, cooldown and concurrency cap refuse before any provider work, and a settled
 * attempt leaves one receipt that records the outcome without the prompt.
 */
function consultCampaign(): AiCampaign
{
    return app(OpenAiCampaignAction::class)->handle(now()->toImmutable(), now()->addDay()->toImmutable());
}

function consultCandidate(AiCandidateActionType $type): ScoredCandidate
{
    $candidate = app()->makeWith(CandidateAction::class, [
        'type' => $type,
        'reason' => 'fixture',
        'parameters' => [],
        'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]);

    return app()->makeWith(ScoredCandidate::class, ['candidate' => $candidate, 'score' => 1.0, 'components' => []]);
}

/** @param array<int, ScoredCandidate> $scored */
function consultTrace(int $playerId, array $scored): DecisionTrace
{
    return app()->makeWith(DecisionTrace::class, [
        'perception' => app()->makeWith(PerceptionSnapshot::class, [
            'playerId' => $playerId,
            'observedAt' => CarbonImmutable::instance(now()),
            'planets' => [],
            'targetReports' => [],
            'availableActions' => [],
            'fleetsaveEligible' => false,
            'recoveryFactor' => 0.0,
            'sourceTimestamps' => [],
            'inboundFleets' => [],
        ]),
        'candidates' => $scored,
        'selected' => $scored[0],
        'rejections' => [],
        'inputHash' => 'consultation-fixture',
    ]);
}

/** @param array<int, mixed> $evidence */
function consult(AiCampaign $campaign, DecisionTrace $trace, array $evidence = []): CampaignConsultationRecommendation
{
    return app(RequestCampaignConsultationAction::class)->handle(
        AiCampaignConsultationTrigger::NewPhase,
        $campaign,
        $trace,
        $evidence,
    );
}

/** @return CampaignConsultationGateway */
function gatewayReturning(CampaignConsultationRecommendation $recommendation): CampaignConsultationGateway
{
    return new class ($recommendation) implements CampaignConsultationGateway {
        public function __construct(private readonly CampaignConsultationRecommendation $recommendation)
        {
        }

        public function recommend(CampaignConsultationRequest $request): CampaignConsultationRecommendation
        {
            return $this->recommendation;
        }
    };
}

/** @param list<int> $evidenceIds */
function completed(int $candidateId, array $evidenceIds = []): CampaignConsultationRecommendation
{
    return new CampaignConsultationRecommendation(
        AiCampaignConsultationStatus::Completed,
        $candidateId,
        AiCampaignConsultationRisk::Low,
        'a reason',
        $evidenceIds,
        10,
        5,
        'inv-1',
        'openai',
        'gpt-5-mini',
    );
}

function timedOut(): CampaignConsultationRecommendation
{
    return new CampaignConsultationRecommendation(
        AiCampaignConsultationStatus::TimedOut,
        null,
        null,
        null,
        [],
        0,
        0,
        null,
        'openai',
        'gpt-5-mini',
    );
}

test('off refuses the consultation without touching a gateway', function (): void {
    app()->bind(CampaignConsultationGateway::class, function (): never {
        throw new LogicException('The gateway must not be resolved when the lane is off.');
    });

    $recommendation = consult(consultCampaign(), consultTrace(1, [consultCandidate(AiCandidateActionType::Build)]));

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Disabled)
        ->and($recommendation->candidateId)->toBeNull();
});

test('a completed recommendation naming a supplied candidate passes through and is receipted', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);
    app()->bind(CampaignConsultationGateway::class, fn (): CampaignConsultationGateway => gatewayReturning(completed(AiCandidateActionType::Build->value)));

    $recommendation = consult(consultCampaign(), consultTrace($this->currentUserId, [consultCandidate(AiCandidateActionType::Build)]));

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Completed)
        ->and($recommendation->candidateId)->toBe(AiCandidateActionType::Build->value);

    $receipt = AiCampaignConsultationReceipt::query()->sole();
    expect($receipt->status)->toBe(AiCampaignConsultationStatus::Completed)
        ->and($receipt->changed_ranking)->toBeFalse()
        ->and($receipt->input_tokens)->toBe(10)
        ->and($receipt->output_tokens)->toBe(5);
});

test('a completed recommendation naming a candidate outside the set is invalid', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);
    app()->bind(CampaignConsultationGateway::class, fn (): CampaignConsultationGateway => gatewayReturning(completed(AiCandidateActionType::Raid->value)));

    $recommendation = consult(consultCampaign(), consultTrace($this->currentUserId, [consultCandidate(AiCandidateActionType::Build)]));

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Invalid)
        ->and($recommendation->candidateId)->toBeNull()
        ->and(AiCampaignConsultationReceipt::query()->sole()->status)->toBe(AiCampaignConsultationStatus::Invalid);
});

test('a completed recommendation citing evidence the brief did not carry is invalid', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);
    app()->bind(CampaignConsultationGateway::class, fn (): CampaignConsultationGateway => gatewayReturning(completed(AiCandidateActionType::Build->value, [99])));

    $recommendation = consult(consultCampaign(), consultTrace($this->currentUserId, [consultCandidate(AiCandidateActionType::Build)]));

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Invalid);
});

test('a daily ceiling refuses the consultation before any provider work', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);
    config(['ai.campaign-consultation.daily_limits.account.attempts' => 0]);
    app()->bind(CampaignConsultationGateway::class, function (): never {
        throw new LogicException('The gateway must not be resolved when the cap refuses the attempt.');
    });

    $recommendation = consult(consultCampaign(), consultTrace($this->currentUserId, [consultCandidate(AiCandidateActionType::Build)]));

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Disabled)
        ->and(AiCampaignConsultationReceipt::query()->count())->toBe(0)
        ->and(Modules\AI\Models\AiStopCounter::query()->where('reason', AiStopReason::ConsultationUsageCap->value)->exists())->toBeTrue();
});

test('a second consultation within the cooldown window is refused', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);
    app()->bind(CampaignConsultationGateway::class, fn (): CampaignConsultationGateway => gatewayReturning(completed(AiCandidateActionType::Build->value)));
    $campaign = consultCampaign();
    $trace = consultTrace($this->currentUserId, [consultCandidate(AiCandidateActionType::Build)]);

    consult($campaign, $trace);

    app()->bind(CampaignConsultationGateway::class, function (): never {
        throw new LogicException('The gateway must not be resolved when the cooldown refuses the attempt.');
    });

    $recommendation = consult($campaign, $trace);

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Disabled)
        ->and(AiCampaignConsultationReceipt::query()->count())->toBe(1)
        ->and(Modules\AI\Models\AiStopCounter::query()->where('reason', AiStopReason::ConsultationCooldown->value)->exists())->toBeTrue();
});

test('a held concurrency slot refuses the consultation', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);
    $lock = Cache::lock('ai:campaign-consultation:0', 60);
    $lock->get();

    try {
        $recommendation = consult(consultCampaign(), consultTrace($this->currentUserId, [consultCandidate(AiCandidateActionType::Build)]));
    } finally {
        $lock->release();
    }

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Disabled)
        ->and(AiCampaignConsultationReceipt::query()->count())->toBe(0)
        ->and(Modules\AI\Models\AiStopCounter::query()->where('reason', AiStopReason::ConsultationConcurrencyCap->value)->exists())->toBeTrue();
});

test('advice mode marks an accepted recommendation as changed for a later ranking', function (): void {
    config(['ai.campaign-consultation.mode' => 'advice']);
    app()->bind(CampaignConsultationGateway::class, fn (): CampaignConsultationGateway => gatewayReturning(completed(AiCandidateActionType::Build->value)));

    consult(consultCampaign(), consultTrace($this->currentUserId, [consultCandidate(AiCandidateActionType::Build)]));

    expect(AiCampaignConsultationReceipt::query()->sole()->changed_ranking)->toBeTrue();
});

test('a timed-out consultation settles its reservation at the reserved maximum once', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);
    app()->bind(CampaignConsultationGateway::class, fn (): CampaignConsultationGateway => gatewayReturning(timedOut()));

    $recommendation = consult(consultCampaign(), consultTrace($this->currentUserId, [consultCandidate(AiCandidateActionType::Build)]));

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::TimedOut)
        ->and(AiCampaignConsultationReceipt::query()->sole()->status)->toBe(AiCampaignConsultationStatus::TimedOut);

    $reservation = Modules\AI\Models\AiUsageReservation::query()->sole();
    expect($reservation->state)->toBe(Modules\AI\Enums\AiUsageReservationState::Settled)
        ->and($reservation->actual_input_tokens)->toBe((int) config('ai.campaign-consultation.maximum_input_tokens'))
        ->and($reservation->actual_output_tokens)->toBe((int) config('ai.campaign-consultation.maximum_output_tokens'));
});
