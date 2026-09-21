<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Models\AiEmotionalEpisode;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiRuntimeSettings;
use OGame\Models\BattleReport;

/**
 * Appraises one observed battle through the configured affect engine.
 *
 * The observation is the audit point: an emotion is only ever recorded against a source
 * the module already decided the AI could legally read, and a repeated run cannot record
 * a second episode because the episode is keyed on that observation.
 */
class AppraiseObservedBattleReportAction
{
    public function handle(int $observationId): AiEmotionalEpisode|null
    {
        // Ablation seam: an enrichment-off baseline keeps the observation and every persisted
        // affect record, and simply does not turn an observation into a new emotion or advance
        // the running state. Nothing is deleted, so re-enabling resumes from the same records.
        if (!app(AiRuntimeSettings::class)->affectEnrichment()) {
            return null;
        }

        /** @var AiObservation|null $observation */
        $observation = AiObservation::query()->find($observationId);

        if ($observation === null || $observation->kind !== AiObservationKind::BattleReportObserved) {
            return null;
        }

        $profile = AiProfile::query()
            ->where('player_id', $observation->player_id)
            ->where('enabled', true)
            ->first();

        if ($profile === null) {
            return null;
        }

        /** @var BattleReport|null $battleReport */
        $battleReport = BattleReport::query()->find($observation->source_id);

        if ($battleReport === null) {
            return null;
        }

        $stimulus = app(MapObservedBattleReportToStimulusAction::class)
            ->handle($observation->player_id, $profile->archetype, $battleReport);

        // A battle the AI did not come off worse in carries no emotion this engine can
        // express, so nothing is recorded rather than an emotion being invented.
        if ($stimulus === null) {
            return null;
        }

        $appraisal = app(AffectEngine::class)->appraiseObservedEvent($stimulus);

        $occurredAt = CarbonImmutable::instance($observation->source_time);

        $episode = app(RecordAiEmotionalEpisodeAction::class)->handle(
            $observation->player_id,
            $observation->id,
            $appraisal->emotion,
            $appraisal->intensity,
            $occurredAt,
        );

        // The running state advances in event time, so a replayed observation lands where it
        // belongs. A repeated reduction of the same observation must not intensify it twice,
        // and the episode is keyed on the observation, so its creation is that signal.
        if ($episode->wasRecentlyCreated) {
            app(UpdateAiAffectStateAction::class)->handle(
                $observation->player_id,
                $appraisal->emotion,
                $appraisal->intensity,
                $occurredAt,
            );

            // Coming off worse in a battle is the durable social fact behind "friends don't
            // forget": the relationship toward the attacker turns colder (the next social
            // decision toward them already reads these scores), and the attack is remembered
            // as an AttackReceived fact so later recall can weigh a betrayal or an apology.
            $this->recordHostility($observation, $occurredAt);
        }

        return $episode;
    }

    private function recordHostility(AiObservation $observation, CarbonImmutable $occurredAt): void
    {
        $attackerPlayerId = (int) $observation->subject_player_id;

        app(RecordAiRelationshipInteractionAction::class)->handle(
            $observation->player_id,
            $attackerPlayerId,
            $observation->id,
            $occurredAt,
            trustChange: -0.20,
            threatChange: 0.30,
            affinityChange: -0.10,
        );

        app(RecordAiMemoryFactAction::class)->handle(
            $observation->player_id,
            $attackerPlayerId,
            AiMemoryPredicate::AttackReceived,
            AiMemoryEvidenceKind::Verified,
            [],
            $observation->id,
            $occurredAt,
        );
    }
}
