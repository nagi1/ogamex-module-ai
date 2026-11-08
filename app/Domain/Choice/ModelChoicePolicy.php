<?php

namespace Modules\AI\Domain\Choice;

use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Models\AiProfile;

/**
 * The simulator's economy model (`ai.rl.economy_model`, EconomyPolicyModel) answers the building and research choices
 * in process. Every other choice (yard, errand) and every choice with no model configured stays the planner's own.
 */
class ModelChoicePolicy implements ChoicePolicy
{
    public function choose(ChoicePoint $point, AiProfile $profile): int
    {
        $model = EconomyPolicyModel::configured();
        if ($model === null || !in_array($point->kind, [ChoicePoint::BUILDING, ChoicePoint::RESEARCH], true)) {
            return $point->teacherIndex;
        }

        return $model->choose($point);
    }

    public function name(): string
    {
        return 'model';
    }
}
