<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\AI\Contracts\CampaignConsultationGateway;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationBrief;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationEvidence;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRecommendation;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRequest;
use Modules\AI\Domain\Conversation\UsageBudgetLimit;
use Modules\AI\Domain\Conversation\UsageBudgetLimits;
use Modules\AI\Domain\Conversation\UsageReservationRequest;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Enums\AiCampaignConsultationMode;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiLanguageTaskKind;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignConsultationReceipt;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Support\AiClock;

/**
 * The consultation lane's one entry point.
 *
 * Admission runs first and `off` never resolves the SDK configuration or contacts a provider.
 * A passing admission builds the redacted brief, refuses on cooldown, concurrency or a daily
 * ceiling, reserves the attempt against the existing usage ledger, transports the brief through
 * the gateway, validates the typed result, settles the reservation exactly once and writes the
 * receipt. Every non-completed status preserves the native campaign decision.
 */
class RequestCampaignConsultationAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    /**
     * @param array<int, CampaignConsultationEvidence> $evidence
     */
    public function handle(AiCampaignConsultationTrigger $trigger, AiCampaign $campaign, DecisionTrace $trace, array $evidence = []): CampaignConsultationRecommendation
    {
        $admission = app(ResolveCampaignConsultationAdmissionAction::class)->forConsultation($trigger);

        if (!$admission->allowed) {
            return $this->disabled();
        }

        $brief = app(BuildCampaignConsultationBriefAction::class)->handle($campaign, $trace, $evidence);

        if ($this->withinCooldown($campaign->id, $trigger)) {
            app(RecordAiStopReasonAction::class)->handle(AiStopReason::ConsultationCooldown, ['campaign_id' => $campaign->id, 'trigger' => $trigger->value]);

            return $this->disabled();
        }

        $slot = $this->acquireConcurrencySlot();

        if ($slot === null) {
            app(RecordAiStopReasonAction::class)->handle(AiStopReason::ConsultationConcurrencyCap, ['campaign_id' => $campaign->id]);

            return $this->disabled();
        }

        try {
            return $this->consult($trigger, $campaign, $trace, $brief, $admission->mode);
        } finally {
            Cache::lock('ai:campaign-consultation:' . $slot, 1)->release();
        }
    }

    private function consult(AiCampaignConsultationTrigger $trigger, AiCampaign $campaign, DecisionTrace $trace, CampaignConsultationBrief $brief, AiCampaignConsultationMode $mode): CampaignConsultationRecommendation
    {
        $requestKey = 'campaign-consultation:' . $campaign->id . ':' . $trigger->value . ':' . $trace->inputHash;

        $reservation = app(ReserveAiUsageAction::class)->handle(
            app()->makeWith(UsageReservationRequest::class, [
                'universeScope' => (string) config('ai.campaign-consultation.universe_scope', 'default'),
                'playerId' => $trace->perception->playerId,
                'conversationKey' => 'campaign:' . $campaign->id,
                'requestKey' => $requestKey,
                'inputTokens' => (int) config('ai.campaign-consultation.maximum_input_tokens', 4_000),
                'outputTokens' => (int) config('ai.campaign-consultation.maximum_output_tokens', 640),
                'reservedAt' => $this->clock->now(),
            ]),
            $this->usageLimits(),
        );

        if ($reservation === null) {
            app(RecordAiStopReasonAction::class)->handle(AiStopReason::ConsultationUsageCap, ['campaign_id' => $campaign->id]);

            return $this->disabled();
        }

        $ladder = app(ResolveAiProviderRouteAction::class)->handle(AiLanguageTaskKind::CampaignConsultation, $this->clock->now());

        $request = app()->makeWith(CampaignConsultationRequest::class, [
            'trigger' => $trigger,
            'serializedBrief' => $brief->serialized,
            'ladder' => $ladder,
            'timeoutSeconds' => (int) config('ai.campaign-consultation.timeout_seconds'),
            'maximumReasonCharacters' => (int) config('ai.campaign-consultation.maximum_reason_characters'),
            'maximumEvidenceIds' => (int) config('ai.campaign-consultation.maximum_evidence_ids'),
            'campaignId' => $campaign->id,
            'candidates' => $brief->candidates,
        ]);

        $recommendation = app(CampaignConsultationGateway::class)->recommend($request);

        $recommendation = $this->validate($recommendation, $brief->candidateIds, $brief->evidenceIds);

        $this->settle($reservation->id, $recommendation);
        $this->writeReceipt($trigger, $campaign, $trace, $recommendation, $reservation->id, $mode);

        return $recommendation;
    }

    /**
     * A completed recommendation may name only a supplied candidate and cite only supplied
     * evidence; anything else is invalid and the native decision stands.
     *
     * @param list<int> $candidateIds
     * @param list<int> $evidenceIds
     */
    private function validate(CampaignConsultationRecommendation $recommendation, array $candidateIds, array $evidenceIds): CampaignConsultationRecommendation
    {
        if ($recommendation->status !== AiCampaignConsultationStatus::Completed) {
            return $recommendation;
        }

        $namesUnknownCandidate = $recommendation->candidateId !== null && !in_array($recommendation->candidateId, $candidateIds, true);
        $citesUnknownEvidence = array_diff($recommendation->evidenceIds, $evidenceIds) !== [];

        if (!$namesUnknownCandidate && !$citesUnknownEvidence) {
            return $recommendation;
        }

        return $this->invalid($recommendation);
    }

    private function settle(int $reservationId, CampaignConsultationRecommendation $recommendation): void
    {
        // A timed-out call may still be completing remotely, so its attempt is charged at the
        // reserved maximum exactly once; every other outcome settles against the reported usage.
        $timedOut = $recommendation->status === AiCampaignConsultationStatus::TimedOut;
        app(SettleAiUsageReservationAction::class)->handle(
            $reservationId,
            $timedOut ? (int) config('ai.campaign-consultation.maximum_input_tokens', 4_000) : $recommendation->inputTokens,
            $timedOut ? (int) config('ai.campaign-consultation.maximum_output_tokens', 640) : $recommendation->outputTokens,
            $this->clock->now(),
            $recommendation->provider,
            $recommendation->model,
        );
    }

    private function writeReceipt(AiCampaignConsultationTrigger $trigger, AiCampaign $campaign, DecisionTrace $trace, CampaignConsultationRecommendation $recommendation, int $reservationId, AiCampaignConsultationMode $mode): void
    {
        $completed = $recommendation->status === AiCampaignConsultationStatus::Completed;

        DB::transaction(function () use ($trigger, $campaign, $trace, $recommendation, $reservationId, $mode, $completed): void {
            AiCampaignConsultationReceipt::query()->create([
                'player_id' => $trace->perception->playerId,
                'campaign_id' => $campaign->id,
                'trigger' => $trigger,
                'status' => $recommendation->status,
                'candidate_id' => $completed ? $recommendation->candidateId : null,
                'evidence_ids' => $completed ? $recommendation->evidenceIds : [],
                'config_revision' => hash('sha256', (string) json_encode(config('ai.campaign-consultation'), JSON_THROW_ON_ERROR)),
                'provider' => $recommendation->provider,
                'model' => $recommendation->model,
                'provider_request_id' => $recommendation->providerRequestId,
                'input_tokens' => $recommendation->inputTokens,
                'output_tokens' => $recommendation->outputTokens,
                'cost' => AiUsageReservation::query()->find($reservationId)?->cost,
                'usage_reservation_id' => $reservationId,
                'changed_ranking' => $completed && $mode === AiCampaignConsultationMode::Advice,
                'request_key' => 'campaign-consultation:' . $campaign->id . ':' . $trigger->value . ':' . $trace->inputHash,
            ]);
        });
    }

    private function withinCooldown(int $campaignId, AiCampaignConsultationTrigger $trigger): bool
    {
        $since = $this->clock->now()->subSeconds(max(1, (int) config('ai.campaign-consultation.trigger_cooldown_seconds', 3600)));

        return AiCampaignConsultationReceipt::query()
            ->where('campaign_id', $campaignId)
            ->where('trigger', $trigger->value)
            ->where('created_at', '>=', $since)
            ->exists();
    }

    /**
     * ponytail: a global Redis lock per slot is the whole concurrency mechanism; a slot leak is
     * bounded by the lock's TTL, and the cap is the number of slots. If the cap ever grows past
     * a handful, replace the slot scan with a counter-based limiter.
     */
    private function acquireConcurrencySlot(): int|null
    {
        $cap = max(1, (int) config('ai.campaign-consultation.concurrency_cap', 1));
        $seconds = (int) config('ai.campaign-consultation.timeout_seconds', 20) + 5;

        for ($slot = 0; $slot < $cap; $slot++) {
            if (Cache::lock('ai:campaign-consultation:' . $slot, $seconds)->get()) {
                return $slot;
            }
        }

        return null;
    }

    private function usageLimits(): UsageBudgetLimits
    {
        return app()->makeWith(UsageBudgetLimits::class, [
            'universe' => $this->usageLimit('universe'),
            'player' => $this->usageLimit('account'),
            'conversation' => $this->usageLimit('campaign'),
        ]);
    }

    private function usageLimit(string $scope): UsageBudgetLimit
    {
        return app()->makeWith(UsageBudgetLimit::class, [
            'attempts' => (int) config('ai.campaign-consultation.daily_limits.' . $scope . '.attempts'),
            'inputTokens' => (int) config('ai.campaign-consultation.daily_limits.' . $scope . '.input_tokens'),
            'outputTokens' => (int) config('ai.campaign-consultation.daily_limits.' . $scope . '.output_tokens'),
        ]);
    }

    private function disabled(): CampaignConsultationRecommendation
    {
        return app()->makeWith(CampaignConsultationRecommendation::class, [
            'status' => AiCampaignConsultationStatus::Disabled,
            'candidateId' => null,
            'risk' => null,
            'reason' => null,
            'evidenceIds' => [],
            'inputTokens' => 0,
            'outputTokens' => 0,
            'providerRequestId' => null,
            'provider' => null,
            'model' => null,
        ]);
    }

    private function invalid(CampaignConsultationRecommendation $recommendation): CampaignConsultationRecommendation
    {
        return app()->makeWith(CampaignConsultationRecommendation::class, [
            'status' => AiCampaignConsultationStatus::Invalid,
            'candidateId' => null,
            'risk' => null,
            'reason' => null,
            'evidenceIds' => [],
            'inputTokens' => $recommendation->inputTokens,
            'outputTokens' => $recommendation->outputTokens,
            'providerRequestId' => $recommendation->providerRequestId,
            'provider' => $recommendation->provider,
            'model' => $recommendation->model,
        ]);
    }
}
