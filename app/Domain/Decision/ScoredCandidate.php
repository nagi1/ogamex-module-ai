<?php

namespace Modules\AI\Domain\Decision;

readonly class ScoredCandidate
{
    /** @param array<string, float> $components */
    public function __construct(
        public CandidateAction $candidate,
        public float $score,
        public array $components,
    ) {
    }

    /** @return array<string, mixed> */
    public function traceData(): array
    {
        return $this->candidate->traceData() + [
            'score' => $this->score,
            'components' => $this->components,
        ];
    }
}
