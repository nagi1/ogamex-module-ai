<?php

namespace Modules\AI\Domain\Decision;

/**
 * One building the account may want next, and the rule that put it on the list.
 *
 * The label is carried rather than recomputed so the recorded intent can say *why* a building was
 * queued -- a facility the chain still needs, or a persona preference -- without the executor
 * having to re-derive a decision it did not make.
 */
readonly class BuildCandidate
{
    public function __construct(
        public int $buildingId,
        public string $reason,
    ) {
    }
}
