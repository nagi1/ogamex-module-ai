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
 */
readonly class AiAuthenticityOverview
{
    /**
     * @param array<string, int> $saveOutcomes
     * @param array<string, int|float> $growth
     */
    public function __construct(
        public int $reactionObservations = 0,
        public int $reactionsInsideWindow = 0,
        public int $reactionsOutsideWindow = 0,
        public array $saveOutcomes = [],
        public array $growth = [],
        public int $distinctReasons = 0,
        public int $distinctContacts = 0,
    ) {
    }
}
