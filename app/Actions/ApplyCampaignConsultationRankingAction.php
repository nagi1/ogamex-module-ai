<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRecommendation;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use Modules\AI\Models\AiProfile;

/**
 * Applies a validated recommendation as a profile-bounded nudge to a ranking.
 *
 * The nudge equals the profile's own selection margin: a less experienced account is swayed more
 * by advice, a veteran barely at all, and the bound is exactly the near-equal window the native
 * selector already uses. A recommendation therefore can promote a candidate that was already in
 * contention, but it can never overtake a clearly superior native choice and can never touch a
 * candidate type that is not in the list — native policy still chooses and dispatches.
 */
class ApplyCampaignConsultationRankingAction
{
    /**
     * @param array<int, ScoredCandidate> $candidates
     * @return array<int, ScoredCandidate>
     */
    public function handle(AiProfile $profile, array $candidates, CampaignConsultationRecommendation $recommendation): array
    {
        if ($recommendation->status !== AiCampaignConsultationStatus::Completed || $recommendation->candidateId === null) {
            return $candidates;
        }

        $delta = $profile->skill_band->selectionMargin();

        return array_map(
            fn (ScoredCandidate $scored): ScoredCandidate => $scored->candidate->type->value !== $recommendation->candidateId
                ? $scored
                : app()->makeWith(ScoredCandidate::class, [
                    'candidate' => $scored->candidate,
                    'score' => $scored->score + $delta,
                    'components' => $scored->components,
                ]),
            $candidates,
        );
    }
}
