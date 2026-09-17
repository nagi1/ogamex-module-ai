<?php

namespace Modules\AI\Actions;

use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Contracts\QueueAiColony;
use Modules\AI\Contracts\QueueAiExpedition;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Contracts\QueueAiRecall;
use Modules\AI\Contracts\QueueAiRecycle;
use Modules\AI\Contracts\QueueAiResearch;
use Modules\AI\Contracts\QueueAiSpy;
use Modules\AI\Contracts\QueueAiTransfer;
use Modules\AI\Contracts\QueueAiUnits;
use Modules\AI\Domain\Decision\QueueableBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableColony;
use Modules\AI\Domain\Decision\QueueableColonyPlanner;
use Modules\AI\Domain\Decision\QueueableFleetSave;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\QueueableRecycle;
use Modules\AI\Domain\Decision\QueueableRecyclePlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\QueueableTransfer;
use Modules\AI\Domain\Decision\QueueableTransferPlanner;
use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiActionResult;

/**
 * Performs a scheduled intent against the host's own queues and fleet paths.
 *
 * This is the whole "decided, now act" step, lifted out of the queue job so the
 * job only leases and records. Each kind is one method that resolves what the
 * intent names — or re-plans when an older work item carried no binding — and
 * then asks the matching queue adapter, which is the module's only contact with
 * the host's state.
 */
class ExecuteAiIntentAction
{
    private const PAYLOAD_BUILDING_ID = 'building_id';

    private const PAYLOAD_RESEARCH_ID = 'research_id';

    private const PAYLOAD_UNIT_ID = 'unit_id';

    private const PAYLOAD_AMOUNT = 'amount';

    private const PAYLOAD_GALAXY = 'galaxy';

    private const PAYLOAD_SYSTEM = 'system';

    private const PAYLOAD_POSITION = 'position';

    private const PAYLOAD_MISSION_TYPE = 'mission_type';

    private const PAYLOAD_DESTINATION_PLANET_ID = 'destination_planet_id';

    private const PAYLOAD_SHADOW_DESTINATION_PLANET_ID = 'shadow_destination_planet_id';

    private const PAYLOAD_TARGET_GALAXY = 'target_galaxy';

    private const PAYLOAD_TARGET_SYSTEM = 'target_system';

    private const PAYLOAD_TARGET_POSITION = 'target_position';

    private const PAYLOAD_TARGET_TYPE = 'target_type';

    private const PAYLOAD_PROBE_COUNT = 'probe_count';

    private const PAYLOAD_SOURCE_PLANET_ID = 'source_planet_id';

    private const PAYLOAD_TARGET_PLANET_ID = 'target_planet_id';

    private const PAYLOAD_METAL = 'metal';

    private const PAYLOAD_CRYSTAL = 'crystal';

    private const PAYLOAD_DEUTERIUM = 'deuterium';

    private const PAYLOAD_REASON = 'reason';

    private const PAYLOAD_SPEED = 'speed';

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    public function execute(AiWorkItem $workItem, int $planetId): array
    {
        return match ($workItem->kind) {
            AiWorkKind::QueueResearch => $this->research($workItem, $planetId),
            AiWorkKind::QueueUnits => $this->units($workItem, $planetId),
            AiWorkKind::Colonize => $this->colony($workItem, $planetId),
            AiWorkKind::Expedition => $this->expedition($workItem, $planetId),
            AiWorkKind::Transfer => $this->transfer($workItem, $planetId),
            AiWorkKind::FleetSave => $this->fleetSave($workItem, $planetId),
            AiWorkKind::Recall => $this->recall($workItem, $planetId),
            AiWorkKind::Spy => $this->spy($workItem, $planetId),
            AiWorkKind::Raid => $this->raid($workItem, $planetId),
            AiWorkKind::Recycle => $this->recycle($workItem, $planetId),
            AiWorkKind::BuildFirstBuilding, AiWorkKind::RunSession => $this->build($workItem, $planetId),
        };
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function build(AiWorkItem $workItem, int $planetId): array
    {
        $buildingId = $workItem->payload[self::PAYLOAD_BUILDING_ID] ?? null;
        $step = is_int($buildingId)
            ? app()->makeWith(QueueableBuilding::class, [
                'planetId' => $planetId,
                'buildingId' => $buildingId,
                'reason' => $this->reason($workItem),
            ])
            : $this->plannedBuild($workItem->player_id);

        if (!$step instanceof QueueableBuilding) {
            return [null, [], 0];
        }

        return [
            app(QueueAiBuilding::class)->handle($workItem->player_id, $step->planetId, $step->buildingId),
            ['building_id' => $step->buildingId, 'build_reason' => $step->reason],
            $step->planetId,
        ];
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function research(AiWorkItem $workItem, int $planetId): array
    {
        $researchId = $workItem->payload[self::PAYLOAD_RESEARCH_ID] ?? null;
        $step = is_int($researchId)
            ? app()->makeWith(QueueableResearch::class, [
                'planetId' => $planetId,
                'researchId' => $researchId,
                'reason' => $this->reason($workItem),
            ])
            : $this->plannedResearch($workItem->player_id);

        if (!$step instanceof QueueableResearch) {
            return [null, [], 0];
        }

        return [
            app(QueueAiResearch::class)->handle($workItem->player_id, $step->planetId, $step->researchId),
            ['research_id' => $step->researchId, 'research_reason' => $step->reason],
            $step->planetId,
        ];
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function units(AiWorkItem $workItem, int $planetId): array
    {
        $unitId = $workItem->payload[self::PAYLOAD_UNIT_ID] ?? null;
        $amount = $workItem->payload[self::PAYLOAD_AMOUNT] ?? null;
        $step = is_int($unitId) && is_int($amount)
            ? app()->makeWith(QueueableUnit::class, [
                'planetId' => $planetId,
                'unitId' => $unitId,
                'amount' => $amount,
                'reason' => $this->reason($workItem),
            ])
            : app(QueueableUnitPlanner::class)->plan($workItem->player_id);

        if (!$step instanceof QueueableUnit) {
            return [null, [], 0];
        }

        return [
            app(QueueAiUnits::class)->handle($workItem->player_id, $step->planetId, $step->unitId, $step->amount),
            ['unit_id' => $step->unitId, 'amount' => $step->amount, 'unit_reason' => $step->reason],
            $step->planetId,
        ];
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function colony(AiWorkItem $workItem, int $planetId): array
    {
        $step = $this->fromPayload(QueueableColony::class, [
            'planetId' => $planetId,
            'galaxy' => $workItem->payload[self::PAYLOAD_GALAXY] ?? null,
            'system' => $workItem->payload[self::PAYLOAD_SYSTEM] ?? null,
            'position' => $workItem->payload[self::PAYLOAD_POSITION] ?? null,
            'missionType' => $workItem->payload[self::PAYLOAD_MISSION_TYPE] ?? null,
        ]) ?? app(QueueableColonyPlanner::class)->plan($workItem->player_id);

        if (!$step instanceof QueueableColony) {
            return [null, [], 0];
        }

        return [
            app(QueueAiColony::class)->handle($workItem->player_id, $step->planetId, $step->galaxy, $step->system, $step->position),
            ['galaxy' => $step->galaxy, 'system' => $step->system, 'position' => $step->position, 'mission_type' => $step->missionType],
            $step->planetId,
        ];
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function transfer(AiWorkItem $workItem, int $planetId): array
    {
        $step = $this->fromPayload(QueueableTransfer::class, [
            'sourcePlanetId' => $workItem->payload[self::PAYLOAD_SOURCE_PLANET_ID] ?? null,
            'targetPlanetId' => $workItem->payload[self::PAYLOAD_TARGET_PLANET_ID] ?? null,
            'metal' => $workItem->payload[self::PAYLOAD_METAL] ?? null,
            'crystal' => $workItem->payload[self::PAYLOAD_CRYSTAL] ?? null,
            'deuterium' => $workItem->payload[self::PAYLOAD_DEUTERIUM] ?? null,
        ]) ?? app(QueueableTransferPlanner::class)->plan($workItem->player_id);

        if (!$step instanceof QueueableTransfer) {
            return [null, [], 0];
        }

        return [
            app(QueueAiTransfer::class)->handle(
                $workItem->player_id,
                $step->sourcePlanetId,
                $step->targetPlanetId,
                $step->metal,
                $step->crystal,
                $step->deuterium,
            ),
            [
                'source_planet_id' => $step->sourcePlanetId,
                'target_planet_id' => $step->targetPlanetId,
                'metal' => $step->metal,
                'crystal' => $step->crystal,
                'deuterium' => $step->deuterium,
            ],
            $step->sourcePlanetId,
        ];
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function expedition(AiWorkItem $workItem, int $planetId): array
    {
        $result = app(QueueAiExpedition::class)->handle(
            $workItem->player_id,
            $planetId,
            (int) ($workItem->payload[self::PAYLOAD_GALAXY] ?? 0),
            (int) ($workItem->payload[self::PAYLOAD_SYSTEM] ?? 0),
        );

        return [$result, [], $planetId];
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function fleetSave(AiWorkItem $workItem, int $planetId): array
    {
        $step = $this->fromPayload(QueueableFleetSave::class, [
            'originPlanetId' => $planetId,
            'destinationPlanetId' => $workItem->payload[self::PAYLOAD_DESTINATION_PLANET_ID] ?? null,
            'missionType' => $workItem->payload[self::PAYLOAD_MISSION_TYPE] ?? null,
            'shadowDestinationPlanetId' => (int) ($workItem->payload[self::PAYLOAD_SHADOW_DESTINATION_PLANET_ID] ?? 0),
            'harvestGalaxy' => (int) ($workItem->payload[self::PAYLOAD_TARGET_GALAXY] ?? 0),
            'harvestSystem' => (int) ($workItem->payload[self::PAYLOAD_TARGET_SYSTEM] ?? 0),
            'harvestPosition' => (int) ($workItem->payload[self::PAYLOAD_TARGET_POSITION] ?? 0),
            'speed' => (float) ($workItem->payload[self::PAYLOAD_SPEED] ?? 1.0),
        ]) ?? app(QueueableFleetSavePlanner::class)->plan($workItem->player_id);

        if (!$step instanceof QueueableFleetSave) {
            return [null, [], 0];
        }

        return [
            app(QueueAiFleetSave::class)->handle(
                $workItem->player_id,
                $step->originPlanetId,
                $step->destinationPlanetId,
                $step->shadowDestinationPlanetId,
                $step->harvestGalaxy,
                $step->harvestSystem,
                $step->harvestPosition,
                $step->speed,
            ),
            ['destination_planet_id' => $step->destinationPlanetId, 'mission_type' => $step->missionType],
            $step->originPlanetId,
        ];
    }

    /**
     * A recall names no target: the parked deployment is found and recalled by
     * the adapter, which owns the ownership check the host's cancel path lacks.
     *
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function recall(AiWorkItem $workItem, int $planetId): array
    {
        return [
            app(QueueAiRecall::class)->handle($workItem->player_id, $planetId),
            [],
            $planetId,
        ];
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function spy(AiWorkItem $workItem, int $planetId): array
    {
        $step = $this->fromPayload(QueueableSpy::class, [
            'planetId' => $planetId,
            'targetGalaxy' => $workItem->payload[self::PAYLOAD_TARGET_GALAXY] ?? null,
            'targetSystem' => $workItem->payload[self::PAYLOAD_TARGET_SYSTEM] ?? null,
            'targetPosition' => $workItem->payload[self::PAYLOAD_TARGET_POSITION] ?? null,
            'targetType' => $workItem->payload[self::PAYLOAD_TARGET_TYPE] ?? null,
            'missionType' => $workItem->payload[self::PAYLOAD_MISSION_TYPE] ?? null,
            'probeCount' => $workItem->payload[self::PAYLOAD_PROBE_COUNT] ?? 1,
        ]) ?? app(QueueableSpyPlanner::class)->plan($workItem->player_id);

        if (!$step instanceof QueueableSpy) {
            return [null, [], 0];
        }

        return [
            app(QueueAiSpy::class)->handle($workItem->player_id, $step->planetId, $step->targetGalaxy, $step->targetSystem, $step->targetPosition, $step->targetType, $step->probeCount),
            ['target_galaxy' => $step->targetGalaxy, 'target_system' => $step->targetSystem, 'target_position' => $step->targetPosition],
            $step->planetId,
        ];
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function recycle(AiWorkItem $workItem, int $planetId): array
    {
        $step = $this->fromPayload(QueueableRecycle::class, [
            'planetId' => $planetId,
            'targetGalaxy' => $workItem->payload[self::PAYLOAD_TARGET_GALAXY] ?? null,
            'targetSystem' => $workItem->payload[self::PAYLOAD_TARGET_SYSTEM] ?? null,
            'targetPosition' => $workItem->payload[self::PAYLOAD_TARGET_POSITION] ?? null,
            'targetType' => $workItem->payload[self::PAYLOAD_TARGET_TYPE] ?? null,
            'missionType' => $workItem->payload[self::PAYLOAD_MISSION_TYPE] ?? null,
        ]) ?? app(QueueableRecyclePlanner::class)->plan($workItem->player_id);

        if (!$step instanceof QueueableRecycle) {
            return [null, [], 0];
        }

        return [
            app(QueueAiRecycle::class)->handle($workItem->player_id, $step->planetId, $step->targetGalaxy, $step->targetSystem, $step->targetPosition, $step->targetType),
            ['target_galaxy' => $step->targetGalaxy, 'target_system' => $step->targetSystem, 'target_position' => $step->targetPosition],
            $step->planetId,
        ];
    }

    /**
     * @return array{0: AiActionResult|null, 1: array<string, mixed>, 2: int}
     */
    private function raid(AiWorkItem $workItem, int $planetId): array
    {
        // A raid has no re-plan: it is only scheduled with a target it already
        // passed the estimator for, so a work item without a target acts on nothing.
        $step = $this->fromPayload(QueueableRaid::class, [
            'originPlanetId' => $planetId,
            'targetGalaxy' => $workItem->payload[self::PAYLOAD_TARGET_GALAXY] ?? null,
            'targetSystem' => $workItem->payload[self::PAYLOAD_TARGET_SYSTEM] ?? null,
            'targetPosition' => $workItem->payload[self::PAYLOAD_TARGET_POSITION] ?? null,
            'targetType' => $workItem->payload[self::PAYLOAD_TARGET_TYPE] ?? null,
            'missionType' => $workItem->payload[self::PAYLOAD_MISSION_TYPE] ?? null,
        ]);

        if (!$step instanceof QueueableRaid) {
            return [null, [], 0];
        }

        return [
            app(QueueAiRaid::class)->handle($workItem->player_id, $step->originPlanetId, $step->targetGalaxy, $step->targetSystem, $step->targetPosition, $step->targetType),
            ['target_galaxy' => $step->targetGalaxy, 'target_system' => $step->targetSystem, 'target_position' => $step->targetPosition],
            $step->originPlanetId,
        ];
    }

    /**
     * The next building the account's economy plan says to queue, only when it is
     * actually a building (the same plan answers with research when that is next).
     */
    private function plannedBuild(int $playerId): ?QueueableBuilding
    {
        $plan = app(QueueableBuildingPlanner::class)->plan($playerId);

        return $plan instanceof QueueableBuilding ? $plan : null;
    }

    /**
     * The next technology the economy plan says to research, only when it is one.
     */
    private function plannedResearch(int $playerId): ?QueueableResearch
    {
        $plan = app(QueueableBuildingPlanner::class)->plan($playerId);

        return $plan instanceof QueueableResearch ? $plan : null;
    }

    private function reason(AiWorkItem $workItem): string
    {
        return (string) ($workItem->payload[self::PAYLOAD_REASON] ?? 'scheduled');
    }

    /**
     * Builds a step from payload fields, or null when any named field is absent.
     * Absence means an older work item that never carried the binding, which the
     * caller then re-plans or drops.
     *
     * @param class-string $class
     * @param array<string, mixed> $fields
     */
    private function fromPayload(string $class, array $fields): object|null
    {
        foreach ($fields as $field) {
            if ($field === null) {
                return null;
            }
        }

        return app()->makeWith($class, $fields);
    }
}
