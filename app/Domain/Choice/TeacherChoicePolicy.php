<?php

namespace Modules\AI\Domain\Choice;

use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Models\AiProfile;

/** The planner's own choice: today's behaviour, the imitation label and every policy's fallback. */
class TeacherChoicePolicy implements ChoicePolicy
{
    public function choose(ChoicePoint $point, AiProfile $profile): int
    {
        return $point->teacherIndex;
    }

    public function name(): string
    {
        return 'teacher';
    }
}
