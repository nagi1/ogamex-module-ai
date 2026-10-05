<?php

namespace Modules\AI\Actions;

use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Domain\Choice\ChoiceRecorder;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Choice\YardChoiceEncoder;
use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\PlanetService;

/**
 * The yard order a login places: the planner's own, unless a choice policy decides for this account (plan/rl). With
 * the teacher policy and no recorder this is exactly `QueueableUnitPlanner::plan()`; otherwise the planet's yard
 * becomes a choice point over every unit it could take, which is recorded and may be answered by the policy. The
 * order still goes through the host's normal queue action, and the planner's standing-order guard still applies.
 */
class DecideAiYardOrderAction
{
    public function __construct(
        private QueueableUnitPlanner $planner,
        private EconomyChoiceEncoder $economy,
        private YardChoiceEncoder $encoder,
        private ChoiceRecorder $recorder,
        private ChoicePolicy $policy,
        private RandomSource $random,
        private PlayerServiceFactory $players,
    ) {
    }

    public function handle(int $playerId, string $decisionKey): ?QueueableUnit
    {
        if ($this->policy->name() === 'teacher' && !$this->recorder->enabled()) {
            return $this->planner->plan($playerId);
        }

        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return $this->planner->plan($playerId);
        }

        $player = $this->players->make($playerId, true);
        $teacher = $this->planner->plan($playerId, $player);
        $planet = $this->planet($player->planets->all(), $teacher);
        if ($planet === null) {
            return $teacher;
        }

        $account = $this->economy->account($profile, $player);
        $point = $this->encoder->point($playerId, $account['state'], $player, $planet, $teacher, $decisionKey . ':yard:' . $planet->getPlanetId());
        if (count($point->legalIndexes()) < 2) {
            return $teacher;
        }

        $learner = $this->isLearner($profile);
        $chosen = $learner ? $this->policy->choose($point, $profile) : $point->teacherIndex;
        $this->recorder->record($point, $profile, $chosen, $learner ? $this->policy->name() : 'teacher', $learner, $account['value']);

        if ($chosen === $point->teacherIndex) {
            return $teacher;
        }

        $candidate = $point->candidates[$chosen];
        if ($candidate->objectId === null) {
            return null;
        }

        $amount = $this->amountOf($planet, $candidate->objectId);

        return app()->makeWith(QueueableUnit::class, [
            'planetId' => $planet->getPlanetId(),
            'unitId' => $candidate->objectId,
            'amount' => $amount,
            'reason' => 'policy:yard',
        ]);
    }

    /**
     * The planet the decision is about: the one the planner ordered on, else the first that can take any order at all.
     *
     * @param array<int, PlanetService> $planets
     */
    private function planet(array $planets, ?QueueableUnit $teacher): ?PlanetService
    {
        foreach ($planets as $planet) {
            if ($teacher !== null && $planet->getPlanetId() === $teacher->planetId) {
                return $planet;
            }
        }

        foreach ($planets as $planet) {
            foreach ($this->planner->yardCandidates($planet, null) as $row) {
                if ($row['legal']) {
                    return $planet;
                }
            }
        }

        return null;
    }

    /** Half of what the planet can pay for, the same rule the candidate row was encoded with. */
    private function amountOf(PlanetService $planet, int $unitId): int
    {
        foreach ($this->planner->yardCandidates($planet, null) as $row) {
            if ($row['unit']->id === $unitId) {
                return $row['amount'];
            }
        }

        return 1;
    }

    private function isLearner(AiProfile $profile): bool
    {
        $share = (float) config('ai.rl.learner_share', 1.0);

        return $share >= 1.0 || $this->random->unitInterval((int) config('ai.rl.seed', 0), 'rl:learner:' . $profile->player_id) < $share;
    }
}
