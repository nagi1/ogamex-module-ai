<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiActionReceiptResultKey;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiBuildingExperienceFeature;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiExperienceCase;
use Modules\AI\Models\AiObservation;
use Modules\AI\Support\AiClock;
use OGame\Models\BuildingQueue;
use OGame\Models\Planet;
use OGame\Services\ObjectService;

class RecordAiBuildingCompletionExperienceAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $planetId, string $machineName, int $targetLevel): AiExperienceCase|null
    {
        $planet = Planet::query()->find($planetId);

        if ($planet === null) {
            return null;
        }

        $matches = AiActionReceipt::query()
            ->where('player_id', $planet->user_id)
            ->where('action_type', AiActionType::QueueBuilding)
            ->where('state', AiReceiptState::Accepted)
            ->where('result->' . AiActionReceiptResultKey::PlanetId->value, $planetId)
            ->get()
            ->map(fn (AiActionReceipt $receipt): BuildingQueue|null => $this->completedQueueForReceipt($receipt, $planetId, $machineName, $targetLevel))
            ->filter()
            ->values();

        // The host event intentionally does not expose a queue ID. Refuse an
        // ambiguous historical match rather than retain outcome evidence for
        // a different accepted queue.
        if ($matches->count() !== 1) {
            return null;
        }

        /** @var BuildingQueue $queue */
        $queue = $matches->sole();
        $observedAt = $this->clock->now();
        $observation = AiObservation::query()->firstOrCreate([
            'player_id' => $planet->user_id,
            'source_type' => AiObservationSource::BuildingQueue,
            'source_id' => $queue->id,
        ], [
            'kind' => AiObservationKind::BuildingCompleted,
            'subject_player_id' => null,
            'source_time' => $observedAt,
            'observed_at' => $observedAt,
        ]);

        return app(RecordAiExperienceOutcomeAction::class)->handle(
            $planet->user_id,
            $observation->id,
            AiExperienceCaseFamily::BuildingUpgrade,
            AiExperienceOutcome::Succeeded,
            AiExperienceFeatureVersion::BuildingUpgradeV1->value,
            AiExperienceRulesetVersion::HostBuildingCompletionV1->value,
            [
                AiBuildingExperienceFeature::PlanetId->value => $planetId,
                AiBuildingExperienceFeature::ObjectId->value => $queue->object_id,
                AiBuildingExperienceFeature::TargetLevel->value => $targetLevel,
            ],
            1,
            0,
        );
    }

    private function completedQueueForReceipt(AiActionReceipt $receipt, int $planetId, string $machineName, int $targetLevel): BuildingQueue|null
    {
        $queueId = (int) ($receipt->result[AiActionReceiptResultKey::QueueId->value] ?? 0);

        if ($queueId === 0) {
            return null;
        }

        $queue = BuildingQueue::query()
            ->whereKey($queueId)
            ->where('planet_id', $planetId)
            ->where('processed', true)
            ->where('object_level_target', $targetLevel)
            ->first();

        if ($queue === null) {
            return null;
        }

        return ObjectService::getObjectById($queue->object_id)->machine_name === $machineName ? $queue : null;
    }
}
