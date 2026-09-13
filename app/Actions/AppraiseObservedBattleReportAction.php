<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Models\AiEmotionalEpisode;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
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

        return app(RecordAiEmotionalEpisodeAction::class)->handle(
            $observation->player_id,
            $observation->id,
            $appraisal->emotion,
            $appraisal->intensity,
            CarbonImmutable::instance($observation->source_time),
        );
    }
}
