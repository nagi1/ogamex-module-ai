<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\BuildCampaignConsultationBriefAction;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationEvidence;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiCandidateActionType;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The redacted brief carries the current turn and the driver evidence that is authorised, non-null
 * and fresh; the campaign state and the legal candidates with their native scores travel structured
 * for the consultation tools instead of being serialized. Missing, invalid, stale and unauthorised
 * evidence is absent, and no candidate parameter or source timestamp ever leaves the module.
 */
function briefCampaign(): Modules\AI\Models\AiCampaign
{
    return app(OpenAiCampaignAction::class)->handle(now()->toImmutable(), now()->addDay()->toImmutable());
}

function scoredCandidate(AiCandidateActionType $type, float $score, string $reason = 'fixture'): ScoredCandidate
{
    $candidate = app()->makeWith(CandidateAction::class, [
        'type' => $type,
        'reason' => $reason,
        'parameters' => [],
        'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
        'sourceTimestamps' => [],
    ]);

    return app()->makeWith(ScoredCandidate::class, ['candidate' => $candidate, 'score' => $score, 'components' => []]);
}

/** @param array<int, ScoredCandidate> $scored */
function briefTrace(array $scored): DecisionTrace
{
    return app()->makeWith(DecisionTrace::class, [
        'perception' => app()->makeWith(PerceptionSnapshot::class, [
            'playerId' => 1,
            'observedAt' => CarbonImmutable::instance(now()),
            'planets' => [],
            'targetReports' => [],
            'availableActions' => [],
            'fleetsaveEligible' => false,
            'recoveryFactor' => 0.0,
            'sourceTimestamps' => [],
            'inboundFleets' => [],
        ]),
        'candidates' => $scored,
        'selected' => $scored[0],
        'rejections' => [],
        'inputHash' => 'brief-fixture',
    ]);
}

function evidence(string $source, string $kind, string|int|float|bool|null $value, CarbonImmutable $collectedAt, bool $authorized = true): CampaignConsultationEvidence
{
    return new CampaignConsultationEvidence($source, $kind, $value, '1.0.0', $collectedAt, $authorized);
}

/** @param array<int, CampaignConsultationEvidence> $evidence */
function buildBrief(Modules\AI\Models\AiCampaign $campaign, DecisionTrace $trace, array $evidence): Modules\AI\Domain\CampaignConsultation\CampaignConsultationBrief
{
    return app(BuildCampaignConsultationBriefAction::class)->handle($campaign, $trace, $evidence);
}

test('every healthy driver field reaches the serialized brief', function (): void {
    $now = now();
    $brief = buildBrief(briefCampaign(), briefTrace([scoredCandidate(AiCandidateActionType::Build, 5.0)]), [
        evidence('fatima', 'mood', -3.95, $now->toImmutable()),
        evidence('cif', 'volition', 10.0, $now->toImmutable()),
        evidence('cbrkit', 'case_similarity', 0.82, $now->toImmutable()),
        evidence('agentos', 'fact_relevance', true, $now->toImmutable()),
    ]);

    $decoded = json_decode($brief->serialized, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['evidence'])->toHaveCount(4)
        ->and($decoded['evidence'][0])->toMatchArray(['id' => 1, 'source' => 'fatima', 'kind' => 'mood', 'value' => -3.95])
        ->and($brief->evidenceIds)->toBe([1, 2, 3, 4]);
});

test('missing, invalid, stale and unauthorised evidence is absent', function (): void {
    $now = now();
    $brief = buildBrief(briefCampaign(), briefTrace([scoredCandidate(AiCandidateActionType::Build, 5.0)]), [
        evidence('fatima', 'mood', null, $now->toImmutable()),
        evidence('cif', 'volition', 10.0, $now->subHour()->toImmutable()),
        evidence('cbrkit', 'case_similarity', 0.82, $now->toImmutable(), authorized: false),
    ]);

    $decoded = json_decode($brief->serialized, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['evidence'])->toBe([])
        ->and($brief->evidenceIds)->toBe([]);
});

test('the brief lists legal candidates by stable action-type id, without parameters', function (): void {
    $brief = buildBrief(briefCampaign(), briefTrace([
        scoredCandidate(AiCandidateActionType::FleetSave, 9.0),
        scoredCandidate(AiCandidateActionType::Build, 4.0),
    ]), []);

    expect($brief->candidateIds)->toBe([AiCandidateActionType::Build->value, AiCandidateActionType::FleetSave->value])
        ->and($brief->candidates[0])->toMatchArray(['id' => AiCandidateActionType::Build->value, 'action' => 'Build'])
        ->and($brief->candidates[1])->toMatchArray(['id' => AiCandidateActionType::FleetSave->value, 'action' => 'FleetSave'])
        ->and($brief->candidates)->each->not->toHaveKey('parameters');
});

test('a duplicated candidate type keeps only its best native score', function (): void {
    $brief = buildBrief(briefCampaign(), briefTrace([
        scoredCandidate(AiCandidateActionType::Build, 2.0, 'lower'),
        scoredCandidate(AiCandidateActionType::Build, 7.0, 'higher'),
    ]), []);

    expect($brief->candidates)->toHaveCount(1)
        ->and($brief->candidates[0]['score'])->toEqual(7.0)
        ->and($brief->candidates[0]['reason'])->toBe('higher');
});
