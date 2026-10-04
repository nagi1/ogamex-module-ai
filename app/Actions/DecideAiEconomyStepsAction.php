<?php

namespace Modules\AI\Actions;

use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Domain\Choice\ChoicePoint;
use Modules\AI\Domain\Choice\ChoiceRecorder;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Decision\QueueableBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
use OGame\Factories\PlayerServiceFactory;

/**
 * The economy steps a login places: the planner's own, unless a choice policy decides for this account
 * (plan/rl). With the teacher policy and no recorder this is exactly `QueueableBuildingPlanner::steps()`;
 * otherwise every free build queue and the lab become a choice point, which is recorded and may be
 * answered by the policy. Whatever is chosen still goes through the host's normal queue actions.
 */
class DecideAiEconomyStepsAction
{
    public function __construct(
        private QueueableBuildingPlanner $planner,
        private EconomyChoiceEncoder $encoder,
        private ChoiceRecorder $recorder,
        private ChoicePolicy $policy,
        private RandomSource $random,
        private PlayerServiceFactory $players,
    ) {
    }

    /** @return list<QueueableBuilding|QueueableResearch> */
    public function handle(int $playerId, string $decisionKey): array
    {
        if ($this->policy->name() === 'teacher' && !$this->recorder->enabled()) {
            return $this->planner->steps($playerId);
        }

        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return $this->planner->steps($playerId);
        }

        $player = $this->players->make($playerId, true);
        $steps = $this->planner->steps($playerId, $player);
        $account = $this->encoder->account($profile, $player);
        $learner = $this->isLearner($profile);

        foreach ($this->planner->choiceSets($playerId, $steps, $player) as $set) {
            $point = $this->encoder->point($playerId, $account['state'], $set, $decisionKey . ':' . ($set['research'] ? 'lab' : 'planet:' . $set['planet']->getPlanetId()));
            $chosen = $learner ? $this->policy->choose($point, $profile) : $point->teacherIndex;
            $this->recorder->record($point, $profile, $chosen, $learner ? $this->policy->name() : 'teacher', $learner, $account['value']);

            if ($chosen !== $point->teacherIndex) {
                $steps = $this->replace($steps, $point, $chosen);
            }
        }

        return array_values($steps);
    }

    /** A hash of the run seed and the player, so the same accounts learn in every run of one seed. */
    private function isLearner(AiProfile $profile): bool
    {
        $share = (float) config('ai.rl.learner_share', 1.0);

        return $share >= 1.0 || $this->random->unitInterval((int) config('ai.rl.seed', 0), 'rl:learner:' . $profile->player_id) < $share;
    }

    /**
     * @param list<QueueableBuilding|QueueableResearch> $steps
     * @return list<QueueableBuilding|QueueableResearch>
     */
    private function replace(array $steps, ChoicePoint $point, int $chosen): array
    {
        $kept = array_values(array_filter($steps, static fn (QueueableBuilding|QueueableResearch $step): bool => $point->research
            ? !$step instanceof QueueableResearch
            : !($step instanceof QueueableBuilding && $step->planetId === $point->planetId)));

        $candidate = $point->candidates[$chosen];
        if ($candidate->objectId === null) {
            return $kept;
        }

        $reason = 'policy:' . $candidate->pass . ':' . $candidate->reason;

        return [...$kept, $point->research
            ? app()->makeWith(QueueableResearch::class, ['planetId' => $point->planetId, 'researchId' => $candidate->objectId, 'reason' => $reason])
            : app()->makeWith(QueueableBuilding::class, ['planetId' => $point->planetId, 'buildingId' => $candidate->objectId, 'reason' => $reason])];
    }
}
