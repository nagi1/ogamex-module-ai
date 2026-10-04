<?php

namespace Modules\AI\Domain\Choice;

use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;

/**
 * The teacher, except that with probability epsilon a legal row is drawn at random. Recorded data then
 * shows what other choices lead to, which pure imitation data never does. The draws are hash-seeded per
 * account and choice, so a seeded run replays.
 */
class EpsilonChoicePolicy implements ChoicePolicy
{
    public function __construct(private RandomSource $random)
    {
    }

    public function choose(ChoicePoint $point, AiProfile $profile): int
    {
        $seed = $profile->random_seed ^ (int) config('ai.rl.seed', 0);
        if ($this->random->unitInterval($seed, 'rl:explore:' . $point->key) >= (float) config('ai.rl.epsilon', 0.1)) {
            return $point->teacherIndex;
        }

        $legal = $point->legalIndexes();

        return $legal[min(count($legal) - 1, (int) floor($this->random->unitInterval($seed, 'rl:pick:' . $point->key) * count($legal)))];
    }

    public function name(): string
    {
        return 'epsilon';
    }
}
