<?php

namespace Modules\AI\Actions;

use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Domain\Choice\ChoicePoint;
use Modules\AI\Domain\Choice\ChoiceRecorder;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Choice\ErrandChoiceEncoder;
use Modules\AI\Domain\Choice\ErrandPolicyModel;
use Modules\AI\Domain\Choice\WideErrandEncoder;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
use OGame\Factories\PlayerServiceFactory;

/**
 * A login's errand: the engine's own selection, unless a choice policy decides for this account (plan/rl). With the
 * teacher policy and no recorder the trace is returned untouched; otherwise the engine's ranked actions become a choice
 * point, which is recorded and may be answered by the policy. A hostile inbound or a save the account is owed is never
 * a choice (the engine's own rule: variance never trades away a real reaction), and whatever is chosen still goes
 * through the schedule and the host's normal gates. An errand model trained in the simulator (`ai.rl.errand_model`, feature
 * version 3) decides natively on the simulator's own columns instead; without one, today's path is untouched.
 */
class DecideAiErrandAction
{
    public function __construct(
        private EconomyChoiceEncoder $economy,
        private ErrandChoiceEncoder $encoder,
        private ChoiceRecorder $recorder,
        private ChoicePolicy $policy,
        private RandomSource $random,
        private PlayerServiceFactory $players,
        private WideErrandEncoder $wide,
    ) {
    }

    public function handle(AiProfile $profile, PerceptionSnapshot $perception, DecisionTrace $trace, string $decisionKey): DecisionTrace
    {
        $model = ErrandPolicyModel::configured($profile);
        if (($model === null && $this->policy->name() === 'teacher' && !$this->recorder->enabled()) || $perception->fleetsaveEligible || $perception->reactionWakeAt !== null) {
            return $trace;
        }

        $player = $this->players->make($profile->player_id, true);
        if ($player->planets->all() === []) {
            return $trace;
        }
        if ($model !== null) {
            return $this->byModel($model, $profile, $trace, $this->wide->point($profile->player_id, $player, $trace, $decisionKey . ':errand'));
        }

        $account = $this->economy->account($profile, $player);
        $point = $this->encoder->point($profile->player_id, $account['state'], $player, $trace, $decisionKey . ':errand');
        if (count($point->candidates) < 3) {
            return $trace;
        }

        $learner = $this->isLearner($profile);
        $chosen = $learner ? $this->policy->choose($point, $profile) : $point->teacherIndex;
        $this->recorder->record($point, $profile, $chosen, $learner ? $this->policy->name() : 'teacher', $learner, $account['value']);

        if ($chosen === $point->teacherIndex) {
            return $trace;
        }

        $candidate = $point->candidates[$chosen] ?? null;
        $picked = $chosen === 0 || $candidate === null ? $this->quiet($trace) : $this->byType($trace, $candidate->objectId, $candidate->reason);
        if ($picked === null) {
            return $trace;
        }

        return $this->selecting($trace, $picked);
    }

    /** The model's row: waiting is the engine's quiet login, any other row the very candidate it encodes. */
    private function byModel(ErrandPolicyModel $model, AiProfile $profile, DecisionTrace $trace, ChoicePoint $point): DecisionTrace
    {
        $chosen = $this->isLearner($profile) ? $model->best($point) : $point->teacherIndex;
        if ($chosen === $point->teacherIndex) {
            return $trace;
        }

        $index = $point->candidates[$chosen]->objectId ?? null;
        $picked = $index === null ? $this->quiet($trace) : $trace->candidates[$index];

        return $picked === null ? $trace : $this->selecting($trace, $picked);
    }

    private function selecting(DecisionTrace $trace, ScoredCandidate $picked): DecisionTrace
    {
        return app()->makeWith(DecisionTrace::class, [
            'perception' => $trace->perception,
            'candidates' => $trace->candidates,
            'selected' => $picked,
            'rejections' => $trace->rejections,
            'inputHash' => $trace->inputHash,
        ]);
    }

    private function quiet(DecisionTrace $trace): ?ScoredCandidate
    {
        foreach ($trace->candidates as $scored) {
            if ($scored->candidate->type === AiCandidateActionType::DoNothing) {
                return $scored;
            }
        }

        return null;
    }

    private function byType(DecisionTrace $trace, ?int $type, string $reason): ?ScoredCandidate
    {
        foreach ($trace->candidates as $scored) {
            if ($scored->candidate->type->value === $type && $scored->candidate->reason === $reason) {
                return $scored;
            }
        }

        return null;
    }

    private function isLearner(AiProfile $profile): bool
    {
        $share = (float) config('ai.rl.learner_share', 1.0);

        return $share >= 1.0 || $this->random->unitInterval((int) config('ai.rl.seed', 0), 'rl:learner:' . $profile->player_id) < $share;
    }
}
