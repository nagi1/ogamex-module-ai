<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;

class DecisionEngine
{
    public function __construct(
        private CandidateActionFactory $candidateActionFactory,
        private UtilityScorer $utilityScorer,
        private RandomSource $randomSource,
    ) {
    }

    public function decide(AiProfile $profile, PerceptionSnapshot $perception, string $decisionKey): DecisionTrace
    {
        $generation = $this->candidateActionFactory->create($perception);
        $scored = $this->utilityScorer->score($profile, $generation, $decisionKey);
        $selected = $this->situationalErrand(
            $scored,
            $this->utilityScorer->select($profile, $scored, $decisionKey),
        );
        $selected = $this->idleOverride(
            $profile,
            $perception,
            $scored,
            $selected,
            $decisionKey,
        );
        $inputHash = hash('sha256', json_encode($perception->traceInput(), JSON_THROW_ON_ERROR));

        return app()->makeWith(DecisionTrace::class, [
            'perception' => $perception,
            'candidates' => $scored,
            'selected' => $selected,
            'rejections' => $generation->rejections,
            'inputHash' => $inputHash,
        ]);
    }

    /**
     * A login's one errand. ScheduleAiIntentAction refills the build queues and the lab in every
     * session whatever wins the slot, so a chore that fills them anyway spends the errand on work
     * that was going to happen — and the fleet errand the situation actually offers (a raid from a
     * report, debris at home, a colony ship, an expedition) never gets picked. The best candidate
     * that is neither a queue chore nor doing nothing takes the slot, unless doing nothing scores
     * higher: a quiet login is still a legitimate choice, so the floor for taking the slot is
     * DoNothing's own score rather than zero. When no errand is offered at all the chore keeps the
     * slot, which is what an account with only an economy to tend does.
     *
     * @param array<int, ScoredCandidate> $scored the whole ranked list, best first
     */
    private function situationalErrand(array $scored, ScoredCandidate $selected): ScoredCandidate
    {
        // ARB-001: the errand slot is spent on what the situation offers, not on the chore that
        // fills itself.
        if (!in_array($scored[0]->candidate->type, [AiCandidateActionType::Build, AiCandidateActionType::Research], true)) {
            return $selected;
        }

        $doNothing = null;
        foreach ($scored as $candidate) {
            if ($candidate->candidate->type === AiCandidateActionType::DoNothing) {
                $doNothing = $candidate;
                break;
            }
        }

        if ($doNothing === null) {
            return $selected;
        }

        // $scored is already ranked best first, so the first candidate past the chores is the best
        // remaining one: if it cannot beat doing nothing, neither can anything ranked below it.
        foreach ($scored as $candidate) {
            if (in_array($candidate->candidate->type, [AiCandidateActionType::Build, AiCandidateActionType::Research, AiCandidateActionType::DoNothing], true)) {
                continue;
            }

            return $candidate->score > $doNothing->score ? $candidate : $selected;
        }

        return $selected;
    }

    /**
     * The rare "opened the game, did nothing, closed it" moment: a small seeded,
     * skill-band-aware draw that picks DoNothing even when a real action won.
     * A save or a reaction wake always wins — variance never trades away a real
     * reaction (V2/V6 safety).
     *
     * @param array<int, ScoredCandidate> $scored
     */
    private function idleOverride(AiProfile $profile, PerceptionSnapshot $perception, array $scored, ScoredCandidate $selected, string $decisionKey): ScoredCandidate
    {
        if ($perception->fleetsaveEligible || $perception->reactionWakeAt !== null) {
            return $selected;
        }

        if ($selected->candidate->type === AiCandidateActionType::DoNothing) {
            return $selected;
        }

        if ($this->randomSource->unitInterval($profile->random_seed, $decisionKey . ':idle') >= $profile->skill_band->idleOverrideProbability()) {
            return $selected;
        }

        foreach ($scored as $candidate) {
            if ($candidate->candidate->type === AiCandidateActionType::DoNothing) {
                return $candidate;
            }
        }

        return $selected;
    }
}
