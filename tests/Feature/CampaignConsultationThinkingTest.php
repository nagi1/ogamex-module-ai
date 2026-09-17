<?php

use Carbon\CarbonImmutable;
use Laravel\Ai\Enums\Lab;
use Modules\AI\Actions\ResolveAiProviderRouteAction;
use Modules\AI\Ai\Agents\OgameCampaignConsultationAgent;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRequest;
use Modules\AI\Domain\Language\AiProviderLadder;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiLanguageTaskKind;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('the campaign consultation agent asks DeepSeek for thinking mode and nothing for the rest', function (): void {
    $agent = app()->makeWith(OgameCampaignConsultationAgent::class, ['request' => thinkingConsultationRequest()]);

    expect($agent->providerOptions(Lab::DeepSeek))->toBe([
        'thinking' => ['type' => 'enabled'],
        'reasoning_effort' => 'high',
    ])
        ->and($agent->providerOptions('deepseek'))->toBe([
            'thinking' => ['type' => 'enabled'],
            'reasoning_effort' => 'high',
        ])
        ->and($agent->providerOptions(Lab::OpenAI))->toBe([]);
});

test('the campaign consultation ladder routes to the thinking model', function (): void {
    config([
        'ai.routing.enabled' => true,
        'ai.routing.ladders.campaign_consultation' => [['provider' => 'deepseek', 'model' => 'deepseek-v4-pro']],
        'ai.providers.deepseek.key' => 'test-key',
    ]);

    $ladder = app(ResolveAiProviderRouteAction::class)->handle(
        AiLanguageTaskKind::CampaignConsultation,
        CarbonImmutable::parse('2026-09-14 12:00:00', 'UTC'),
    );

    expect($ladder->toProviderMap())->toBe(['deepseek' => 'deepseek-v4-pro']);
});

function thinkingConsultationRequest(): CampaignConsultationRequest
{
    return app()->makeWith(CampaignConsultationRequest::class, [
        'trigger' => AiCampaignConsultationTrigger::NewPhase,
        'serializedBrief' => '{}',
        'ladder' => app()->makeWith(AiProviderLadder::class, ['rungs' => []]),
        'timeoutSeconds' => 20,
        'maximumReasonCharacters' => 400,
        'maximumEvidenceIds' => 8,
        'campaignId' => 1,
        'candidates' => [],
    ]);
}
