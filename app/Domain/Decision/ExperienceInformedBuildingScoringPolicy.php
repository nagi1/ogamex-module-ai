<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Domain\Experience\RankedExperience;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;

/**
 * Weighs an AI's own finalized outcomes alongside its seeded persona preference.
 *
 * The seeded policy answers what this persona would like to build; this evidence says
 * what actually happened the last times it built it. Both inputs are module-owned, so a
 * driver substitution changes how the cases are ranked but never which evidence exists,
 * and a cold start contributes nothing instead of guessing.
 *
 * The adjustment stays small on purpose. Remembered outcomes resolve a near-tie between
 * similar choices; they never outvote a persona preference, and they never decide
 * legality, which stays with the host action gateway.
 */
class ExperienceInformedBuildingScoringPolicy implements BuildingScoringPolicy
{
    /** Bounded so one remembered outcome cannot dominate the seeded preference. */
    private const MAXIMUM_CASES = 20;

    public function __construct(
        private readonly BuildingScoringPolicy $seeded,
        private readonly ExperienceEngine $experience,
    ) {
    }

    public function score(AiProfile $profile, FirstBuildingTarget $target): int
    {
        return $this->seeded->score($profile, $target) + $this->experienceAdjustment($profile, $target);
    }

    /**
     * Only the built object is known before this AI has built anything, so every other
     * feature stays unknown and similarity is decided by the object alone.
     */
    private function experienceAdjustment(AiProfile $profile, FirstBuildingTarget $target): int
    {
        $weight = (int) config('ai.cognition.experience.decision_weight', 20);

        if ($weight === 0) {
            return 0;
        }

        $ranked = $this->experience->rankSimilarExperiences(
            app()->makeWith(ExperienceQuery::class, [
                'playerId' => $profile->player_id,
                'family' => AiExperienceCaseFamily::BuildingUpgrade,
                'featureVersion' => AiExperienceFeatureVersion::BuildingUpgradeV1->value,
                'rulesetVersion' => AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
                'features' => [AiBuildingExperienceFeature::ObjectId->value => $target->value],
                'limit' => self::MAXIMUM_CASES,
            ]),
        );

        // Only a case that matches on every feature the module knows about is evidence
        // about this choice. The engine's similarity is a mean over shared features, so a
        // partial score describes a different situation rather than a weaker precedent —
        // and object identifiers are compared numerically, which would otherwise make an
        // outcome for one building look like faint evidence for its neighbour.
        // An uncertain outcome is worth correspondingly less than a settled one.
        $evidence = array_filter(array_map(
            static fn (RankedExperience $case): float => $case->similarity >= 1.0
                ? $case->utility * (1 - $case->uncertainty)
                : 0.0,
            $ranked,
        ), static fn (float $value): bool => $value !== 0.0);

        if ($evidence === []) {
            return 0;
        }

        return (int) round($weight * array_sum($evidence) / count($evidence));
    }
}
