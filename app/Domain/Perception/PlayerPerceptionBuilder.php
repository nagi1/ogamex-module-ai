<?php

namespace Modules\AI\Domain\Perception;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Support\AiClock;

class PlayerPerceptionBuilder
{
    public function __construct(
        private PlayerObservationService $playerObservationService,
        private AiClock $clock,
    ) {
    }

    public function build(int $playerId, ?int $upcomingAbsenceMinutes = null): PerceptionSnapshot
    {
        return $this->fromObservation($this->playerObservationService->ownedState($playerId), $upcomingAbsenceMinutes);
    }

    /**
     * This adapter is intentionally whitelist-based. Future host observation
     * additions must be explicitly mapped here before policy code can see them.
     *
     * @param array<string, mixed> $observation
     */
    public function fromObservation(array $observation, ?int $upcomingAbsenceMinutes = null): PerceptionSnapshot
    {
        $observedAt = $this->observedAt($observation);

        return app()->makeWith(PerceptionSnapshot::class, [
            'playerId' => (int) ($observation['player_id'] ?? 0),
            'observedAt' => $observedAt,
            'planets' => $this->visiblePlanets((array) ($observation['planets'] ?? [])),
            'targetReports' => $this->targetReports((array) ($observation['target_reports'] ?? [])),
            'availableActions' => $this->availableActions((array) ($observation['available_actions'] ?? [])),
            'fleetsaveEligible' => (bool) ($observation['fleetsave_eligible'] ?? false),
            'inboundFleets' => $this->inboundFleets((array) ($observation['inbound_fleets'] ?? [])),
            'recallEligible' => (bool) ($observation['recall_eligible'] ?? false),
            'recoveryFactor' => max(0.0, min(1.0, (float) ($observation['recovery_factor'] ?? 0))),
            'sourceTimestamps' => $this->sourceTimestamps((array) ($observation['source_timestamps'] ?? []), $observedAt),
            'upcomingAbsenceMinutes' => $upcomingAbsenceMinutes,
            'fleetSlotsFree' => (int) ($observation['fleet_slots_free'] ?? 0),
            'colonizeEligible' => (bool) ($observation['colonize_eligible'] ?? false),
            'reactionWakeAt' => $this->reactionWakeAt($observation),
        ]);
    }

    /** @param array<string, mixed> $observation */
    private function reactionWakeAt(array $observation): ?int
    {
        return isset($observation['reaction_wake_at']) && is_numeric($observation['reaction_wake_at'])
            ? (int) $observation['reaction_wake_at']
            : null;
    }

    /** @param array<string, mixed> $observation */
    private function observedAt(array $observation): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp((int) ($observation['observed_at'] ?? $this->clock->now()->getTimestamp()));
    }

    /**
     * @param array<int, mixed> $observedPlanets
     * @return list<array{id:int, resources:array{metal:float, crystal:float, deuterium:float}}>
     */
    private function visiblePlanets(array $observedPlanets): array
    {
        $visiblePlanets = [];
        foreach ($observedPlanets as $planet) {
            if (!is_array($planet) || !isset($planet['id'])) {
                continue;
            }

            $resources = (array) ($planet['resources'] ?? []);
            $visiblePlanets[] = [
                'id' => (int) $planet['id'],
                'resources' => [
                    'metal' => (float) ($resources['metal'] ?? 0),
                    'crystal' => (float) ($resources['crystal'] ?? 0),
                    'deuterium' => (float) ($resources['deuterium'] ?? 0),
                ],
            ];
        }

        return $visiblePlanets;
    }

    /**
     * @param array<int, mixed> $reports
     * @return list<array<string, mixed>>
     */
    private function targetReports(array $reports): array
    {
        $visibleReports = [];
        foreach ($reports as $report) {
            if (!is_array($report) || !isset($report['report_id'], $report['observed_at'])) {
                continue;
            }

            $visibleReports[] = [
                'report_id' => (int) $report['report_id'],
                'observed_at' => (int) $report['observed_at'],
                'expires_at' => (int) ($report['expires_at'] ?? 0),
                'confidence' => max(0.0, min(1.0, (float) ($report['confidence'] ?? 0))),
                'travel_cost' => max(0.0, min(1.0, (float) ($report['travel_cost'] ?? 1))),
                'activity' => isset($report['activity']) && is_bool($report['activity']) ? $report['activity'] : null,
                'attack_permitted' => (bool) ($report['attack_permitted'] ?? false),
            ];
        }

        return $visibleReports;
    }

    /**
     * @param array<string, mixed> $actions
     * @return array<string, bool>
     */
    private function availableActions(array $actions): array
    {
        $result = [];
        foreach (AiCapability::cases() as $capability) {
            $result[$capability->value] = (bool) ($actions[$capability->value] ?? false);
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $fleets
     * @return list<array{mission_id:int, mission_type:int, time_arrival:int, planet_id_to:int}>
     */
    private function inboundFleets(array $fleets): array
    {
        $visible = [];
        foreach ($fleets as $fleet) {
            if (!is_array($fleet) || !isset($fleet['mission_id'], $fleet['time_arrival'], $fleet['planet_id_to'])) {
                continue;
            }

            $visible[] = [
                'mission_id' => (int) $fleet['mission_id'],
                'mission_type' => (int) ($fleet['mission_type'] ?? 0),
                'time_arrival' => (int) $fleet['time_arrival'],
                'planet_id_to' => (int) $fleet['planet_id_to'],
            ];
        }

        return $visible;
    }

    /**
     * @param array<string, mixed> $timestamps
     * @return array<string, string>
     */
    private function sourceTimestamps(array $timestamps, CarbonImmutable $observedAt): array
    {
        $result = ['owned_state' => $observedAt->toIso8601String()];
        foreach ($timestamps as $source => $timestamp) {
            if (is_int($timestamp)) {
                $result[$source] = CarbonImmutable::createFromTimestamp($timestamp)->toIso8601String();
            }
        }

        return $result;
    }
}
