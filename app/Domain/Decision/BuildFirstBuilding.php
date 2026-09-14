<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;

/**
 * The persona's ranking of the economy buildings it would like next.
 *
 * Ranking rather than picking, because only the caller can ask the host whether a building is
 * legal, affordable and allowed on the planet it would stand on. The top of this list is a
 * preference, not a decision: the planning action walks the list until the host accepts something,
 * so an account whose favourite building is blocked builds its next favourite instead of nothing.
 */
class BuildFirstBuilding
{
    public function __construct(private BuildingScoringPolicy $buildingScoringPolicy)
    {
    }

    /** @return list<BuildCandidate> this persona's targets, most wanted first */
    public function ranked(AiProfile $profile): array
    {
        $scores = [];
        foreach (FirstBuildingTarget::cases() as $target) {
            $scores[$target->value] = $this->buildingScoringPolicy->score($profile, $target);
        }

        // Ties keep the enum's own order, so one persona has one ordering, reproducible from its
        // seed alone.
        arsort($scores, SORT_NUMERIC);

        return array_map(
            static fn (int $buildingId): BuildCandidate => app()->makeWith(BuildCandidate::class, [
                'buildingId' => $buildingId,
                'reason' => 'persona:' . FirstBuildingTarget::from($buildingId)->name,
            ]),
            array_map(intval(...), array_keys($scores)),
        );
    }
}
