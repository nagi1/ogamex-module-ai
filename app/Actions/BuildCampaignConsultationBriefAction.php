<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\CampaignConsultation\CampaignConsultationBrief;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationEvidence;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Support\AiClock;

/**
 * Builds the one redacted, bounded brief a campaign consultation is answered from.
 *
 * The brief carries only what the lane is permitted to say: the module's own campaign state
 * (never a private fact or a stronghold coordinate), the legal executable candidates and their
 * native scores (never their parameters or source timestamps), and the driver evidence that is
 * healthy — authorised, non-null and fresh. Missing, invalid, stale and unauthorised evidence
 * is simply absent, so a provider can never be handed a field the module did not admit.
 */
class BuildCampaignConsultationBriefAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    /**
     * @param array<int, CampaignConsultationEvidence> $evidence
     */
    public function handle(AiCampaign $campaign, DecisionTrace $trace, array $evidence): CampaignConsultationBrief
    {
        $candidates = $this->candidates($trace->candidates);
        $healthyEvidence = $this->healthyEvidence($evidence);

        $serialized = json_encode([
            'campaign' => [
                'id' => $campaign->id,
                'state' => $campaign->state->value,
                'starts_at' => $campaign->starts_at?->toIso8601String(),
                'ends_at' => $campaign->ends_at?->toIso8601String(),
                'open_objectives' => $campaign->objectives()->whereNull('completed_at')->count(),
                'completed_objectives' => $campaign->objectives()->whereNotNull('completed_at')->count(),
            ],
            'candidates' => $candidates,
            'evidence' => array_map(
                static fn (CampaignConsultationEvidence $item, int $index): array => [
                    'id' => $index + 1,
                    'source' => $item->source,
                    'kind' => $item->kind,
                    'value' => $item->value,
                ],
                $healthyEvidence,
                array_keys($healthyEvidence),
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return app()->makeWith(CampaignConsultationBrief::class, [
            'serialized' => $serialized,
            'candidateIds' => array_values(array_unique(array_map(
                static fn (array $candidate): int => $candidate['id'],
                $candidates,
            ))),
            'evidenceIds' => array_map(static fn (int $index): int => $index + 1, array_keys($healthyEvidence)),
        ]);
    }

    /**
     * One entry per distinct candidate action type, carrying the type's best native score. The
     * id is the stable action-type value, so a recommendation names a capability rather than a
     * position in a list that reordering would change.
     *
     * @param array<int, ScoredCandidate> $scored
     * @return list<array{id:int, action:string, reason:string, score:float}>
     */
    private function candidates(array $scored): array
    {
        $best = [];

        foreach ($scored as $candidate) {
            $id = $candidate->candidate->type->value;

            if (!isset($best[$id]) || $candidate->score > $best[$id]['score']) {
                $best[$id] = [
                    'id' => $id,
                    'action' => $candidate->candidate->type->name,
                    'reason' => $candidate->candidate->reason,
                    'score' => $candidate->score,
                ];
            }
        }

        // The brief is deterministic: a stable sort key means an identical replay produces
        // an identical brief regardless of the order candidates arrived in.
        usort($best, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $best;
    }

    /**
     * @param array<int, CampaignConsultationEvidence> $evidence
     * @return list<CampaignConsultationEvidence>
     */
    private function healthyEvidence(array $evidence): array
    {
        $deadline = $this->clock->now()->subSeconds(max(1, (int) config('ai.campaign-consultation.maximum_advice_age_seconds', 900)));

        return array_values(array_filter(
            $evidence,
            static fn (CampaignConsultationEvidence $item): bool => $item->authorized
                && $item->value !== null
                && $item->collectedAt->gte($deadline),
        ));
    }
}
