<?php

namespace Modules\AI\Domain\Advisor;

/**
 * What the caller acts on: the option to carry out and, when the option is spoken and the advisor
 * wrote a line that kept its facts, that line. Off, observe, or any failure: the rule's choice and
 * no text.
 */
readonly class AdvisorVerdict
{
    public function __construct(
        public string $choice,
        public string|null $text = null,
        public string|null $advice = null,
    ) {
    }

    public function is(string $option): bool
    {
        return $this->choice === $option;
    }
}
