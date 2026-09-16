<?php

use Modules\AI\Actions\ApplyCampaignConsultationRankingAction;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRecommendation;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCampaignConsultationRisk;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * A validated recommendation is applied as a nudge bounded by the profile's own selection
 * margin: it can promote a candidate that was already in contention, never one that was not
 * offered, and never by more than the near-equal window the native selector already uses.
 */
function rankingCandidate(AiCandidateActionType $type, float $score): ScoredCandidate
{
    $candidate = app()->makeWith(CandidateAction::class, [
        'type' => $type,
        'reason' => 'fixture',
        'parameters' => [],
        'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]);

    return app()->makeWith(ScoredCandidate::class, ['candidate' => $candidate, 'score' => $score, 'components' => []]);
}

function rankingProfile(AiSkillBand $skillBand): AiProfile
{
    return AiProfile::create([
        'player_id' => 1,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => $skillBand,
        'random_seed' => 1,
        'enabled' => true,
    ]);
}

function recommendation(int|null $candidateId): CampaignConsultationRecommendation
{
    return new CampaignConsultationRecommendation(
        AiCampaignConsultationStatus::Completed,
        $candidateId,
        AiCampaignConsultationRisk::Low,
        'a reason',
        [],
        10,
        5,
        'inv-1',
        'openai',
        'gpt-5-mini',
    );
}

test('a completed recommendation boosts only the matching candidate within the profile bound', function (): void {
    $profile = rankingProfile(AiSkillBand::Standard);
    $fleetSave = rankingCandidate(AiCandidateActionType::FleetSave, 9.0);
    $build = rankingCandidate(AiCandidateActionType::Build, 4.0);

    $adjusted = app(ApplyCampaignConsultationRankingAction::class)->handle(
        $profile,
        [$fleetSave, $build],
        recommendation(AiCandidateActionType::Build->value),
    );

    expect($adjusted[0]->score)->toBe(9.0)
        ->and($adjusted[1]->score)->toBe(4.0 + $profile->skill_band->selectionMargin())
        ->and($adjusted[1]->candidate->type)->toBe(AiCandidateActionType::Build);
});

test('the nudge is bounded by the profile skill band', function (): void {
    $novice = rankingProfile(AiSkillBand::Novice);
    $build = rankingCandidate(AiCandidateActionType::Build, 4.0);

    $adjusted = app(ApplyCampaignConsultationRankingAction::class)->handle($novice, [$build], recommendation(AiCandidateActionType::Build->value));

    expect($adjusted[0]->score)->toBe(4.0 + $novice->skill_band->selectionMargin())
        ->and($novice->skill_band->selectionMargin())->toBeGreaterThan(AiSkillBand::Veteran->selectionMargin());
});

test('a recommendation for a type not offered changes nothing', function (): void {
    $profile = rankingProfile(AiSkillBand::Standard);
    $fleetSave = rankingCandidate(AiCandidateActionType::FleetSave, 9.0);

    $adjusted = app(ApplyCampaignConsultationRankingAction::class)->handle(
        $profile,
        [$fleetSave],
        recommendation(AiCandidateActionType::Raid->value),
    );

    expect($adjusted[0]->score)->toBe(9.0);
});

test('a non-completed recommendation leaves the ranking alone', function (): void {
    $profile = rankingProfile(AiSkillBand::Standard);
    $fleetSave = rankingCandidate(AiCandidateActionType::FleetSave, 9.0);

    $adjusted = app(ApplyCampaignConsultationRankingAction::class)->handle($profile, [$fleetSave], recommendation(null));

    expect($adjusted[0]->score)->toBe(9.0);
});
