<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Modules\AI\Actions\BuildCampaignConsultationBriefAction;
use Modules\AI\Actions\DeclareAiCampaignObjectiveAction;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationBrief;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Infrastructure\Language\Tools\CampaignFactsTool;
use Modules\AI\Infrastructure\Language\Tools\LegalCandidatesTool;
use Modules\AI\Models\AiCampaign;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The S8 read-only tools: each is one bounded, host-read class the consultation agent exposes
 * instead of a stuffed prompt. A tool returns evidence, never an authority, and the lane's
 * deterministic validator still re-checks whatever the model proposes.
 */

/** @param array<int, ScoredCandidate> $scored */
function toolsTrace(array $scored): DecisionTrace
{
    return app()->makeWith(DecisionTrace::class, [
        'perception' => app()->makeWith(PerceptionSnapshot::class, [
            'playerId' => 1,
            'observedAt' => now()->toImmutable(),
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
        'inputHash' => 'tools-fixture',
    ]);
}

function toolsCandidate(AiCandidateActionType $type, float $score): ScoredCandidate
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

function toolsCampaign(): AiCampaign
{
    return app(OpenAiCampaignAction::class)->handle(now()->toImmutable(), now()->addDay()->toImmutable());
}

test('the campaign facts tool returns the one campaign state it is scoped to', function (): void {
    $campaign = toolsCampaign();
    app(DeclareAiCampaignObjectiveAction::class)->handle($campaign->id, $this->currentPlanetId);

    $tool = app()->makeWith(CampaignFactsTool::class, ['campaignId' => $campaign->id]);

    $facts = json_decode((string) $tool->handle(new Request(['campaign_id' => $campaign->id])), true, 512, JSON_THROW_ON_ERROR);

    expect($facts['id'])->toBe($campaign->id)
        ->and($facts['state'])->toBe('Preparing')
        ->and($facts['open_objectives'])->toBe(1)
        ->and($facts['completed_objectives'])->toBe(0);
});

test('the campaign facts tool refuses a campaign it is not scoped to', function (): void {
    $campaign = toolsCampaign();

    $tool = app()->makeWith(CampaignFactsTool::class, ['campaignId' => $campaign->id]);

    expect((string) $tool->handle(new Request(['campaign_id' => 999_999_999])))->toBe('{}');
});

test('the campaign facts tool declares an allowlisted argument and a word-level description', function (): void {
    $tool = app()->makeWith(CampaignFactsTool::class, ['campaignId' => 1]);

    expect($tool->schema(new JsonSchemaTypeFactory()))->toHaveKeys(['campaign_id'])
        ->and((string) $tool->description())->not->toContain('metal');
});

test('the legal candidates tool returns the candidates it was given', function (): void {
    $candidates = [
        ['id' => 3, 'action' => 'Build', 'reason' => 'fixture', 'score' => 2.5],
        ['id' => 6, 'action' => 'Research', 'reason' => 'fixture', 'score' => 1.25],
    ];

    $tool = app()->makeWith(LegalCandidatesTool::class, ['candidates' => $candidates]);

    expect(json_decode((string) $tool->handle(new Request()), true, 512, JSON_THROW_ON_ERROR))->toBe($candidates);
});

test('the brief is the current turn only, with the facts moved to the tools', function (): void {
    $campaign = toolsCampaign();
    $trace = toolsTrace([
        toolsCandidate(AiCandidateActionType::Build, 2.0),
        toolsCandidate(AiCandidateActionType::Research, 1.0),
    ]);

    /** @var CampaignConsultationBrief $brief */
    $brief = app(BuildCampaignConsultationBriefAction::class)->handle($campaign, $trace, []);

    // The serialized prompt carries the current turn and admitted evidence only; candidates travel
    // structured for the tool.
    expect($brief->serialized)->toBe(json_encode(['campaign_id' => $campaign->id, 'evidence' => []], JSON_THROW_ON_ERROR))
        ->and($brief->candidateIds)->toBe([AiCandidateActionType::Build->value, AiCandidateActionType::Research->value])
        ->and($brief->candidates)->toBe([
            ['id' => AiCandidateActionType::Build->value, 'action' => 'Build', 'reason' => 'fixture', 'score' => 2.0],
            ['id' => AiCandidateActionType::Research->value, 'action' => 'Research', 'reason' => 'fixture', 'score' => 1.0],
        ]);
});
