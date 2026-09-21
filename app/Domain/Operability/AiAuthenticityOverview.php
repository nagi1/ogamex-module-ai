<?php

namespace Modules\AI\Domain\Operability;

/**
 * Whether the accounts are distinguishable from one another and from a bot. Four findings, each
 * derived from rows that already exist.
 *
 * @property int $reactionObservations
 * @property int $reactionsInsideWindow
 * @property int $reactionsOutsideWindow
 * @property array<string, int> $saveOutcomes
 * @property array<string, int|float> $growth
 * @property int $distinctReasons
 * @property int $distinctContacts
 * @property float $interactionEntropy
 * @property float $entropyBaseline
 * @property list<array{type: string, count: int}> $interactionTypes
 * @property array{spread: int, distinct: int} $wakeSpread
 */
readonly class AiAuthenticityOverview
{
    /**
     * @param array<string, int> $saveOutcomes
     * @param array<string, int|float> $growth
     * @param list<array{type: string, count: int}> $interactionTypes
     * @param array{spread: int, distinct: int} $wakeSpread
     */
    public function __construct(
        public int $reactionObservations = 0,
        public int $reactionsInsideWindow = 0,
        public int $reactionsOutsideWindow = 0,
        public array $saveOutcomes = [],
        public array $growth = [],
        public int $distinctReasons = 0,
        public int $distinctContacts = 0,
        public float $interactionEntropy = 0.0,
        public float $entropyBaseline = 0.84,
        public array $interactionTypes = [],
        public array $wakeSpread = ['spread' => 0, 'distinct' => 0],
    ) {
    }
}
