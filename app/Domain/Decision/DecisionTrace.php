<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Domain\Perception\PerceptionSnapshot;

readonly class DecisionTrace
{
    /**
     * @param array<int, ScoredCandidate> $candidates
     * @param array<string, string> $rejections
     */
    public function __construct(
        public PerceptionSnapshot $perception,
        public array $candidates,
        public ScoredCandidate $selected,
        public array $rejections,
        public string $inputHash,
    ) {
    }

    /** @return array<string, mixed> */
    public function record(): array
    {
        return [
            'selected_action' => $this->selected->candidate->type,
            'selected_reason' => $this->selected->candidate->reason,
            'candidates' => array_map(static fn (ScoredCandidate $candidate): array => $candidate->traceData(), $this->candidates),
            'score_components' => $this->selected->components + ['rejections' => $this->rejections],
            'source_timestamps' => $this->selected->candidate->sourceTimestamps,
            'input_hash' => $this->inputHash,
            'observed_at' => $this->perception->observedAt,
        ];
    }
}
