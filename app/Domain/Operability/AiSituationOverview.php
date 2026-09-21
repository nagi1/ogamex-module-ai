<?php

namespace Modules\AI\Domain\Operability;

/**
 * The five review-loop questions, answered (improvement-loop.md). Each row carries the question
 * (as a language key), the figure, the window and the evidence class — measured, code-read or
 * inferred — never an impression. The view renders this; it never recomputes it.
 *
 * @property list<array{question: string, figure: string, evidence: string, window: int}> $questions
 */
readonly class AiSituationOverview
{
    /**
     * @param list<array{question: string, figure: string, evidence: string, window: int}> $questions
     */
    public function __construct(
        public int $days = 1,
        public array $questions = [],
    ) {
    }
}
