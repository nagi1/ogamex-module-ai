<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiCandidateActionType;

/** @phpstan-type ScoreFeature array{resource_need:float,safety:float,target_confidence:float,travel_cost:float,recovery:float} */
readonly class CandidateAction
{
    /**
     * @param array<string, scalar> $parameters
     * @param ScoreFeature $features
     * @param array<string, string> $sourceTimestamps
     */
    public function __construct(
        public AiCandidateActionType $type,
        public string $reason,
        public array $parameters,
        public array $features,
        public array $sourceTimestamps,
    ) {
    }

    /** @return array<string, mixed> */
    public function traceData(): array
    {
        return [
            'action' => $this->type->name,
            'reason' => $this->reason,
            'parameters' => $this->parameters,
            'source_timestamps' => $this->sourceTimestamps,
        ];
    }
}
