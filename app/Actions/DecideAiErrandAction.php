<?php

namespace Modules\AI\Actions;

use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Domain\Choice\ChoiceRecorder;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Choice\ErrandChoiceEncoder;
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
 * through the schedule and the host's normal gates.
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
    ) {
    }

    public function handle(AiProfile $profile, PerceptionSnapshot $perception, DecisionTrace $trace, string $decisionKey): DecisionTrace
    {
        if (($this->policy->name() === 'teacher' && !$this->recorder->enabled()) || $perception->fleetsaveEligible || $perception->reactionWakeAt !== null) {
            return $trace;
        }

        $player = $this->players->make($profile->player_id, true);
        if ($player->planets->all() === []) {
            return $trace;
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

        $picked = $chosen === 0 ? $this->quiet($trace) : $this->byType($trace, $point->candidates[$chosen]->objectId, $point->candidates[$chosen]->reason);
        if ($picked === null) {
            return $trace;
        }

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
