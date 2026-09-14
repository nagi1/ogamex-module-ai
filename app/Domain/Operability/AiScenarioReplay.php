<?php

namespace Modules\AI\Domain\Operability;

use Carbon\CarbonImmutable;

/**
 * The outcome of replaying one saved scenario.
 *
 * A replay is a decision the real engine made from a written input, at the frozen time that
 * input carries and with the persona seed it names, so running it twice gives the same answer.
 * The scenario and its result are both reported, which is what makes a replay evidence rather
 * than an anecdote: someone else can run the same file and see the same ranking.
 *
 * @property array<string, float> $components
 * @property array<string, string> $refusals
 * @property list<array{action: string, score: float}> $alternatives
 */
readonly class AiScenarioReplay
{
    /**
     * @param array<string, float> $components
     * @param array<string, string> $refusals
     * @param list<array{action: string, score: float}> $alternatives
     */
    public function __construct(
        public string $name = '',
        public string $persona = '',
        public CarbonImmutable|null $observedAt = null,
        public string $decisionKey = '',
        public string $selectedAction = '',
        public string $selectedReason = '',
        public float $selectedScore = 0.0,
        public array $components = [],
        public array $refusals = [],
        public array $alternatives = [],
    ) {
    }
}
