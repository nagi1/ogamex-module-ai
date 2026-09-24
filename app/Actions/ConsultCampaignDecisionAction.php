<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\CampaignConsultation\CampaignConsultationEvidence;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\UtilityScorer;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignConsultationSignal;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\AiRuntimeSettings;
use Modules\AI\Support\PsychSimTheoryOfMind;

/**
 * Consumes one open campaign-consultation signal against the decision a session just made.
 *
 * The consultation lane is built around a DecisionTrace, and traces only exist inside a session,
 * so this is where a material campaign event finally reaches a real decision: the account's
 * active campaign is found, the oldest unconsumed signal is taken, the lane is asked once, and a
 * completed recommendation re-ranks the candidates the native scorer already produced before the
 * final selection. Native policy still chooses and dispatches — the recommendation can only nudge
 * a candidate that was already legal and already in contention.
 *
 * `off` (the default) never resolves the SDK or contacts a provider; the signal is consumed
 * anyway, so a disabled lane does not grow an open-signal backlog that fires the moment an
 * operator turns it on. One consultation per signal, never a retry of the same event.
 */
class ConsultCampaignDecisionAction
{
    public function __construct(
        private readonly UtilityScorer $scorer,
        private readonly AiClock $clock,
    ) {
    }

    public function handle(AiProfile $profile, DecisionTrace $trace, string $decisionKey): DecisionTrace
    {
        $campaign = AiCampaign::query()
            ->where('state', AiCampaignState::Active)
            ->oldest('id')
            ->first();

        if ($campaign === null) {
            return $trace;
        }

        $signal = AiCampaignConsultationSignal::query()
            ->where('campaign_id', $campaign->id)
            ->whereNull('consumed_at')
            ->oldest('fired_at')
            ->first();

        if ($signal === null) {
            return $trace;
        }

        $recommendation = app(RequestCampaignConsultationAction::class)->handle($signal->trigger, $campaign, $trace, $this->theoryOfMindEvidence($profile));

        $signal->update(['consumed_at' => $this->clock->now()]);

        if ($recommendation->status !== AiCampaignConsultationStatus::Completed || $recommendation->candidateId === null) {
            return $trace;
        }

        // A recommendation the model is not sure of does not move the ranking: the deterministic
        // decision stands, and the suppression is recorded so the operator can see it.
        if (!$recommendation->mayMoveRanking(app(AiRuntimeSettings::class)->campaignMinimumConfidence())) {
            app(RecordAiStopReasonAction::class)->handle(AiStopReason::ConsultationLowConfidence, [
                'campaign_id' => $campaign->id,
                'confidence' => $recommendation->confidence,
            ]);

            return $trace;
        }

        $candidates = app(ApplyCampaignConsultationRankingAction::class)->handle($profile, $trace->candidates, $recommendation);
        $selected = $this->scorer->select($profile, $candidates, $decisionKey);

        return app()->makeWith(DecisionTrace::class, [
            'perception' => $trace->perception,
            'candidates' => $candidates,
            'selected' => $selected,
            'rejections' => $trace->rejections,
            'inputHash' => $trace->inputHash,
        ]);
    }

    /**
     * The account's read of the player it rates most threatening, as one bounded evidence item
     * for the consultation. A wary read advises holding back; no relationship or no driver read
     * adds nothing, so a native consultation is never bloated by an empty signal.
     *
     * @return list<CampaignConsultationEvidence>
     */
    private function theoryOfMindEvidence(AiProfile $profile): array
    {
        $threat = AiRelationship::query()
            ->where('player_id', $profile->player_id)
            ->where('threat', '>', 0)
            ->orderByDesc('threat')
            ->value('threat');

        if ($threat === null) {
            return [];
        }

        $stance = app(PsychSimTheoryOfMind::class)->stanceFor((float) $threat);

        if ($stance === null) {
            return [];
        }

        return [app()->makeWith(CampaignConsultationEvidence::class, [
            'source' => 'psychsim',
            'kind' => 'foe_read',
            'value' => $stance->word(),
            'version' => 'depth-1',
            'collectedAt' => $this->clock->now(),
            'authorized' => true,
        ])];
    }
}
