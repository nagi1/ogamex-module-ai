<?php

namespace Modules\AI\Domain\Operability;

/**
 * What an operator needs to see before deciding whether the population can grow.
 *
 * The overview reports limits as configured and work as it actually stands, so the page never
 * has to explain a difference between them: a stopped population shows the cap that stopped it
 * next to the reason counters that recorded it.
 *
 * @property array<string, int> $limits
 * @property list<array<string, mixed>> $stopReasons
 * @property array<string, int> $population
 * @property array<string, int> $actions
 * @property array<string, int|float> $language
 */
readonly class AiOperabilityOverview
{
    /**
     * @param array<string, int> $limits
     * @param list<array<string, mixed>> $stopReasons
     * @param array<string, int> $population
     * @param array<string, int> $actions
     * @param array<string, int|float> $language
     */
    public function __construct(
        public bool $workEnabled = true,
        public string|null $switchReason = null,
        public string|null $switchedAt = null,
        public int|null $switchedByPlayerId = null,
        public array $limits = [],
        public array $stopReasons = [],
        public array $population = [],
        public array $actions = [],
        public array $language = [],
    ) {
    }
}
