<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;

/**
 * The intentionally small first decision: request a normal mine upgrade.
 *
 * The host action gateway remains the authority on whether this intent is
 * currently legal or affordable.
 */
class BuildFirstBuilding
{
    public function __construct(private BuildingScoringPolicy $buildingScoringPolicy)
    {
    }

    /**
     * @return array{building_id: int, reason: string, input_revision: string}
     */
    public function choose(AiProfile $profile): array
    {
        $scores = [];
        foreach (FirstBuildingTarget::cases() as $target) {
            $scores[$target->value] = $this->buildingScoringPolicy->score($profile, $target);
        }
        arsort($scores, SORT_NUMERIC);
        $target = FirstBuildingTarget::from((int) array_key_first($scores));

        return [
            'building_id' => $target->value,
            'reason' => 'first_building:' . $target->name,
            'input_revision' => hash('sha256', implode(':', [$profile->id, $profile->random_seed, $profile->archetype->value])),
        ];
    }
}
