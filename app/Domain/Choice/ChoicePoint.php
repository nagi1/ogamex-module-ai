<?php

namespace Modules\AI\Domain\Choice;

/**
 * One free queue of one account at one instant: the candidates the planner offers (row 0 is waiting),
 * the account's state as numbers, and which row the planner itself chose.
 */
readonly class ChoicePoint
{
    /**
     * @param list<float> $state
     * @param list<ChoiceCandidate> $candidates
     */
    public function __construct(
        public int $playerId,
        public int $planetId,
        public bool $research,
        public array $state,
        public array $candidates,
        public int $teacherIndex,
        public string $key,
    ) {
    }

    /** @return list<int> the rows the host would accept now; waiting is always one */
    public function legalIndexes(): array
    {
        return array_keys(array_filter($this->candidates, static fn (ChoiceCandidate $candidate): bool => $candidate->legal));
    }
}
