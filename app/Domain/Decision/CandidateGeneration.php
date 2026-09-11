<?php

namespace Modules\AI\Domain\Decision;

readonly class CandidateGeneration
{
    /**
     * @param array<int, CandidateAction> $candidates
     * @param array<string, string> $rejections
     */
    public function __construct(public array $candidates, public array $rejections)
    {
    }
}
