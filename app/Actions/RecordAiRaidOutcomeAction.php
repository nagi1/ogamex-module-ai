<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceFeatureVersion;
use Modules\AI\Enums\AiExperienceOutcome;
use Modules\AI\Enums\AiExperienceRulesetVersion;
use Modules\AI\Enums\AiRaidExperienceFeature;
use Modules\AI\Models\AiExperienceCase;
use OGame\Models\BattleReport;

/**
 * Turns a raid's committed battle report into a raid-outcome experience case.
 *
 * The loot figure is the host's own `loot` column on the report — the resources
 * the attacker actually captured, never a module estimate. The case is keyed by
 * the target's coordinates so the raid planner can later blacklist a target the
 * account keeps coming home empty from.
 */
class RecordAiRaidOutcomeAction
{
    public function handle(int $playerId, int $observationId, BattleReport $report): AiExperienceCase|null
    {
        $loot = $report->loot ?? [];
        if ($loot === []) {
            return null;
        }

        $metalEquivalent = (float) ($loot['metal'] ?? 0)
            + 1.5 * (float) ($loot['crystal'] ?? 0)
            + 2.0 * (float) ($loot['deuterium'] ?? 0);

        $outcome = $metalEquivalent > 0 ? AiExperienceOutcome::Succeeded : AiExperienceOutcome::Failed;

        return app(RecordAiExperienceOutcomeAction::class)->handle(
            $playerId,
            $observationId,
            AiExperienceCaseFamily::Raid,
            $outcome,
            AiExperienceFeatureVersion::RaidV1->value,
            AiExperienceRulesetVersion::HostBattleReportLootV1->value,
            [
                AiRaidExperienceFeature::Galaxy->value => (int) $report->planet_galaxy,
                AiRaidExperienceFeature::System->value => (int) $report->planet_system,
                AiRaidExperienceFeature::Position->value => (int) $report->planet_position,
                AiRaidExperienceFeature::Loot->value => $metalEquivalent,
            ],
            $metalEquivalent > 0 ? 1.0 : 0.0,
            0,
        );
    }
}
