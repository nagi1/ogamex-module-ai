<?php

namespace Modules\AI\Domain\Perception;

use Carbon\CarbonImmutable;

/**
 * Immutable, deliberately narrow input to decision policies. It contains
 * only host-published observations; no policy receives live opponent models.
 */
readonly class PerceptionSnapshot
{
    /**
     * @param array<int, array{id:int, resources:array<string, float|int>}> $planets
     * @param array<int, array<string, mixed>> $targetReports
     * @param array<string, bool> $availableActions
     * @param list<array{mission_id:int, mission_type:int, time_arrival:int, planet_id_to:int}> $inboundFleets
     * @param array<string, string> $sourceTimestamps
     */
    public function __construct(
        public int $playerId,
        public CarbonImmutable $observedAt,
        public array $planets,
        public array $targetReports,
        public array $availableActions,
        public bool $fleetsaveEligible,
        public float $recoveryFactor,
        public array $sourceTimestamps,
        public array $inboundFleets = [],
    ) {
    }

    public function totalResources(): float
    {
        $total = 0.0;
        foreach ($this->planets as $planet) {
            foreach ($planet['resources'] as $amount) {
                $total += (float) $amount;
            }
        }

        return $total;
    }

    /** @return array<string, mixed> */
    public function traceInput(): array
    {
        return [
            'player_id' => $this->playerId,
            'observed_at' => $this->observedAt->toIso8601String(),
            'planets' => $this->planets,
            'target_reports' => $this->targetReports,
            'available_actions' => $this->availableActions,
            'fleetsave_eligible' => $this->fleetsaveEligible,
            'inbound_fleets' => $this->inboundFleets,
            'recovery_factor' => $this->recoveryFactor,
            'source_timestamps' => $this->sourceTimestamps,
        ];
    }
}
