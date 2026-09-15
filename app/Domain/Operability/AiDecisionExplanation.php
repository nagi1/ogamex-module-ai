<?php

namespace Modules\AI\Domain\Operability;

use Carbon\CarbonImmutable;

/**
 * A redacted reading of one recorded decision.
 *
 * The explanation is built from the trace's decision fields only: what was chosen, why, which
 * evidence decided it and what lost. It deliberately omits the recorded candidate parameters,
 * because those carry exact coordinates and object ids that an operator does not need in order
 * to judge whether the policy behaved sensibly — and because a page that is safe to leave open
 * is worth more than one that dumps every field.
 *
 * @property array<string, float> $components
 * @property array<string, string> $refusals
 * @property list<array{action: string, score: float}> $alternatives
 * @property array<string, string> $evidence
 */
readonly class AiDecisionExplanation
{
    /**
     * @param array<string, float> $components
     * @param array<string, string> $refusals
     * @param list<array{action: string, score: float}> $alternatives
     * @param array<string, string> $evidence
     */
    public function __construct(
        public int $traceId = 0,
        public int $playerId = 0,
        public CarbonImmutable|null $observedAt = null,
        public string $selectedAction = '',
        public string $selectedReason = '',
        public float $selectedScore = 0.0,
        public array $components = [],
        public array $refusals = [],
        public array $alternatives = [],
        public array $evidence = [],
    ) {
    }

    /**
     * The same redacted fields the console prints, in the shape a review parses and diffs. It stays
     * redacted by construction: the explanation holds no candidate parameters, so the JSON cannot
     * carry them either.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'player_id' => $this->playerId,
            'observed_at' => $this->observedAt?->toDateTimeString(),
            'selected_action' => $this->selectedAction,
            'selected_reason' => $this->selectedReason,
            // Rounded to the two decimals the console prints, so the two renderings cannot disagree
            // about the same decision.
            'selected_score' => round($this->selectedScore, 2),
            'components' => $this->components,
            'alternatives' => $this->alternatives,
            'refusals' => $this->refusals,
            'evidence' => $this->evidence,
        ];
    }
}
