<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Modules\AI\Ai\Agents\OgameCampaignConsultationAgent;
use Modules\AI\Contracts\CampaignConsultationGateway;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRequest;
use Modules\AI\Domain\Language\AiProviderLadder;
use Modules\AI\Enums\AiCampaignConsultationRisk;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Infrastructure\Language\LaravelAiCampaignConsultationGateway;
use Modules\AI\Infrastructure\Language\NullCampaignConsultationGateway;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The transport seam: the null gateway answers disabled without loading provider configuration,
 * the SDK gateway maps structured envelopes into typed recommendations and classifies transport
 * versus schema failures, and the agent's schema is bounded to the supplied candidates and
 * evidence. Every non-completed envelope preserves the native campaign decision.
 */
function consultationRequest(bool $emptyLadder = false): CampaignConsultationRequest
{
    return new CampaignConsultationRequest(
        AiCampaignConsultationTrigger::NewPhase,
        'brief',
        [3, 6],
        app()->makeWith(AiProviderLadder::class, ['rungs' => $emptyLadder ? [] : [['provider' => 'openai', 'model' => 'gpt-5-mini']]]),
        20,
        400,
        8,
    );
}

test('the null gateway answers disabled without touching provider configuration', function (): void {
    $recommendation = app(NullCampaignConsultationGateway::class)->recommend(consultationRequest());

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Disabled)
        ->and($recommendation->candidateId)->toBeNull();
});

test('an empty provider ladder fails before any prompt is made', function (): void {
    $recommendation = app(LaravelAiCampaignConsultationGateway::class)->recommend(consultationRequest(emptyLadder: true));

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Failed)
        ->and($recommendation->provider)->toBe('')
        ->and($recommendation->model)->toBe('');
});

test('a throwing provider is classified as failed', function (): void {
    OgameCampaignConsultationAgent::fake([
        fn () => throw new RuntimeException('Provider unavailable.'),
    ])->preventStrayPrompts();

    $recommendation = app(LaravelAiCampaignConsultationGateway::class)->recommend(consultationRequest());

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Failed)
        ->and($recommendation->provider)->toBe('openai')
        ->and($recommendation->model)->toBe('gpt-5-mini');
});

test('a provider timeout is classified by its message', function (): void {
    OgameCampaignConsultationAgent::fake([
        fn () => throw new RuntimeException('The request timed out.'),
    ])->preventStrayPrompts();

    $recommendation = app(LaravelAiCampaignConsultationGateway::class)->recommend(consultationRequest());

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::TimedOut);
});

test('a non-structured reply is a provider failure', function (): void {
    OgameCampaignConsultationAgent::fake([
        'plain text without a structured envelope',
    ])->preventStrayPrompts();

    $recommendation = app(LaravelAiCampaignConsultationGateway::class)->recommend(consultationRequest());

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Failed)
        ->and($recommendation->candidateId)->toBeNull();
});

test('a valid structured envelope maps to a completed recommendation', function (): void {
    OgameCampaignConsultationAgent::fake([[
        'candidate_id' => 3,
        'risk' => 'low',
        'reason' => 'a reason',
        'evidence_ids' => [1],
    ]])->preventStrayPrompts();

    $recommendation = app(LaravelAiCampaignConsultationGateway::class)->recommend(consultationRequest());

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Completed)
        ->and($recommendation->candidateId)->toBe(3)
        ->and($recommendation->risk)->toBe(AiCampaignConsultationRisk::Low)
        ->and($recommendation->reason)->toBe('a reason')
        ->and($recommendation->evidenceIds)->toBe([1]);
});

test('a null candidate keeps the native decision and still completes', function (): void {
    OgameCampaignConsultationAgent::fake([[
        'candidate_id' => null,
        'risk' => 'moderate',
        'reason' => 'keep the native decision',
        'evidence_ids' => [],
    ]])->preventStrayPrompts();

    $recommendation = app(LaravelAiCampaignConsultationGateway::class)->recommend(consultationRequest());

    expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Completed)
        ->and($recommendation->candidateId)->toBeNull();
});

test('malformed structured envelopes are invalid and never name a candidate', function (): void {
    OgameCampaignConsultationAgent::fake([
        ['candidate_id' => null, 'risk' => 'bogus', 'reason' => 'x', 'evidence_ids' => []],
        ['candidate_id' => null, 'risk' => 'low', 'reason' => '   ', 'evidence_ids' => []],
        ['candidate_id' => null, 'risk' => 'low', 'reason' => 5, 'evidence_ids' => []],
        ['candidate_id' => null, 'risk' => 'low', 'reason' => 'x', 'evidence_ids' => 'nope'],
        ['candidate_id' => 'three', 'risk' => 'low', 'reason' => 'x', 'evidence_ids' => []],
        ['candidate_id' => null, 'risk' => 'low', 'reason' => 'x', 'evidence_ids' => ['a']],
        ['candidate_id' => null, 'risk' => 'low', 'reason' => 'x', 'evidence_ids' => [0]],
    ])->preventStrayPrompts();
    $gateway = app(LaravelAiCampaignConsultationGateway::class);

    foreach (range(1, 7) as $ignored) {
        $recommendation = $gateway->recommend(consultationRequest());
        expect($recommendation->status)->toBe(AiCampaignConsultationStatus::Invalid)
            ->and($recommendation->candidateId)->toBeNull();
    }
});

test('the agent declares its bounded schema and untrusted-data instructions', function (): void {
    $agent = app()->makeWith(OgameCampaignConsultationAgent::class, ['request' => consultationRequest()]);

    expect($agent->instructions())->toContain('3, 6')
        ->and($agent->schema(new JsonSchemaTypeFactory()))->toHaveKeys(['candidate_id', 'risk', 'reason', 'evidence_ids']);
});

test('an enabled lane resolves the SDK gateway through the provider binding', function (): void {
    config(['ai.campaign-consultation.mode' => 'observe']);

    expect(app(CampaignConsultationGateway::class))->toBeInstanceOf(LaravelAiCampaignConsultationGateway::class);
});
