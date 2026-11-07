<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Advisor\Advisor;
use Modules\AI\Domain\Advisor\AdvisorArea;
use Modules\AI\Domain\Advisor\AdvisorFacts;
use Modules\AI\Domain\Advisor\AdvisorQuestion;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationEvidence;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\UtilityScorer;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiStopReason;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignConsultationSignal;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\AiRuntimeSettings;
use Modules\AI\Support\PsychSimTheoryOfMind;
use OGame\Models\EspionageReport;
use OGame\Models\Message;

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
        $trace = $this->adviseOffense($trace);

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

    /**
     * Area 1 of the advisor (offense, off by default): when the session's raid candidates include a defended target
     * (its own last spy report saw ships or defence), the advisor may pick which target to hit, or to hold and take
     * the best other move. The options are exactly the candidates the planners already made legal, each with what
     * the account's own report showed and its age; the rule's pick is the native selection. Any failure keeps it.
     */
    private function adviseOffense(DecisionTrace $trace): DecisionTrace
    {
        $advisor = app(Advisor::class);
        if (!$advisor->enabled(AdvisorArea::Offense)) {
            return $trace;
        }

        $playerId = $trace->perception->playerId;
        $raids = [];
        $hold = null;
        foreach ($trace->candidates as $scored) {
            $reportId = (int) ($scored->candidate->parameters['report_id'] ?? 0);
            if ($scored->candidate->type === AiCandidateActionType::Raid && $reportId > 0) {
                $raids['raid:' . $reportId] ??= $scored;
            } elseif ($hold === null || $scored->score > $hold->score) {
                $hold = $scored;
            }
        }

        $reports = EspionageReport::query()
            ->whereIn('id', array_map(static fn (string $id): int => (int) substr($id, 5), array_keys($raids)))
            ->whereIn('id', Message::query()->where('user_id', $playerId)->whereNotNull('espionage_report_id')->select('espionage_report_id'))
            ->get()->keyBy('id');
        $options = [];
        $defended = false;
        $targets = [];
        foreach ($raids as $id => $scored) {
            $report = $reports->get((int) substr($id, 5));
            if ($report === null) {
                continue;
            }

            $ships = (int) array_sum(array_map('intval', (array) $report->ships));
            $defense = (int) array_sum(array_map('intval', (array) $report->defense));
            $defended = $defended || $ships + $defense > 0;
            $targets[(int) $report->planet_user_id] = true;
            $options[] = ['id' => $id, 'summary' => 'attack the planet at ' . $report->planet_galaxy . ':' . $report->planet_system . ':' . $report->planet_position, 'forecast' => [
                'my_report_age_hours' => intdiv(max(0, now()->getTimestamp() - (int) $report->created_at?->getTimestamp()), 3600),
                'resources_seen' => (int) array_sum(array_map('intval', array_intersect_key((array) $report->resources, array_flip(['metal', 'crystal', 'deuterium'])))),
                'ships_seen' => $ships,
                'defense_seen' => $defense,
                'native_score' => round($scored->score, 1),
            ]];
        }

        if (!$defended) {
            return $trace;
        }

        if ($hold !== null) {
            $options[] = ['id' => 'hold', 'summary' => 'attack nothing now: ' . strtolower($hold->candidate->type->name), 'forecast' => ['native_score' => round($hold->score, 1)]];
        }

        $selected = $trace->selected->candidate;
        $rule = $selected->type === AiCandidateActionType::Raid ? 'raid:' . (int) ($selected->parameters['report_id'] ?? 0) : 'hold';
        $facts = app(AdvisorFacts::class);
        $battles = [];
        foreach (array_keys($targets) as $owner) {
            foreach ($facts->reports($playerId, $owner) as $item) {
                if ($item['kind'] === 'own_battle_report') {
                    $battles[] = $item;
                }
            }
        }

        $verdict = $advisor->consult(new AdvisorQuestion(
            AdvisorArea::Offense,
            $playerId,
            'hard_target',
            $options,
            $rule,
            ['me' => $facts->standing($playerId), 'targets' => array_map(fn (int $owner): array => ['player' => $owner] + $facts->standing($owner), array_keys($targets))],
            $battles,
        ));

        $chosen = $verdict->choice === 'hold' ? $hold : ($raids[$verdict->choice] ?? null);
        if ($chosen === null || $verdict->choice === $rule) {
            return $trace;
        }

        return app()->makeWith(DecisionTrace::class, [
            'perception' => $trace->perception,
            'candidates' => $trace->candidates,
            'selected' => $chosen,
            'rejections' => $trace->rejections,
            'inputHash' => $trace->inputHash,
        ]);
    }
}
