<?php

namespace Modules\AI\Support;

use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiCognitionMode;
use Modules\AI\Enums\AiToMStance;
use Modules\AI\Infrastructure\Cognition\PsychSimClient;
use Modules\AI\Models\AiRelationship;

/**
 * The module's one translation of OGame relationship state into a theory-of-mind read.
 *
 * PsychSim answers a single cooperate/defect stance from a defection incentive; the module owns
 * the mapping (threat scales into that incentive) and the typed result, so every consumer —
 * social exchanges, alliance and buddy admission, the conversation reply and the campaign
 * brief — reads the same wary account instead of re-deriving it.
 */
class PsychSimTheoryOfMind
{
    /** The counterparty's payoff for defecting on a cooperator, on the sidecar's 0..2 scale. */
    private const DEFECTION_INCENTIVE_SCALE = 2.0;

    /**
     * The account's depth-one read of a counterparty it rates at this threat (0..1).
     *
     * Threat doubles into the sidecar's 0..2 incentive, so the model cooperates below half and
     * defects above it: an account reads a more-than-half-threatening counterparty as someone
     * who will exploit it. Null means the driver had no answer and the caller keeps its native
     * stance.
     */
    public function stanceFor(float $threat): AiToMStance|null
    {
        // A driver that is not the selected cognition engine is never contacted: the account
        // keeps its native stance, and an unselected sidecar costs nothing and answers nothing.
        // The client is resolved here, not injected, so an unselected driver costs no wiring.
        if (!$this->enabled()) {
            return null;
        }

        $decision = app(PsychSimClient::class)->decide($threat * self::DEFECTION_INCENTIVE_SCALE);

        return $decision === null ? null : AiToMStance::from($decision);
    }

    /**
     * The account's read of a specific counterparty, from the relationship the module recorded.
     * Null means there is no recorded relationship (or no driver read), so the caller keeps its
     * native decision.
     */
    public function stanceToward(int $playerId, int $otherPlayerId): AiToMStance|null
    {
        $threat = AiRelationship::query()
            ->where('player_id', $playerId)
            ->where('other_player_id', $otherPlayerId)
            ->value('threat');

        return $threat === null ? null : $this->stanceFor((float) $threat);
    }

    private function enabled(): bool
    {
        return AiCognitionMode::tryFrom((string) config('ai.cognition.mode', AiCognitionMode::External->value)) !== AiCognitionMode::Native
            && AiCognitionDriver::tryFrom((string) config('ai.cognition.driver', AiCognitionDriver::Native->value)) === AiCognitionDriver::PsychSim;
    }
}
