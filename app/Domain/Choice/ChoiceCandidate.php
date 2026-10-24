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
        /** The host would accept it once the planet can pay and the planet can: saving for it is a legal answer. */
        public bool $payableLater = false,
    ) {
    }

    /** Whether a policy that builds "the next thing" may name this row: payable now, or worth waiting for. */
    public function buildable(): bool
    {
        return $this->legal || $this->payableLater;
    }
}
