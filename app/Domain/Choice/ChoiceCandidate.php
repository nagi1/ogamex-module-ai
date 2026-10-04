<?php

namespace Modules\AI\Domain\Choice;

/** One row a choice policy ranks: an object to queue, or waiting (objectId null). */
readonly class ChoiceCandidate
{
    /** @param list<float> $features */
    public function __construct(
        public ?int $objectId,
        public ?string $pass,
        public string $reason,
        public bool $legal,
        public array $features,
    ) {
    }
}
