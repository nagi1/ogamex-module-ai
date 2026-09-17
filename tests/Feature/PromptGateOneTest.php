<?php

use Modules\AI\Ai\Agents\OgameCampaignConsultationAgent;
use Modules\AI\Ai\Agents\OgameConversationReplyAgent;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRequest;
use Modules\AI\Domain\Language\AiProviderLadder;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

require_once __DIR__ . '/../Support/LanguageTestFixtures.php';

/**
 * Gate 1 over the LLM prompts: an authored prompt carries how a professional reasons, never the
 * object universe. A machine name in a prompt is the same defect as a hardcoded table, observed
 * in eleven surveyed bots — it silently stops meaning what it said the moment the host adds an
 * object. The schema is an allowlist of field names, so the check runs over the instructions,
 * which is the one place a model could be handed an object id as truth.
 */
test('the LLM agent prompts carry no hardcoded object universe', function (): void {
    $consultationRequest = promptGateConsultationRequest();

    $prompts = [
        (string) app()->makeWith(OgameConversationReplyAgent::class, ['request' => languageRequest()])->instructions(),
        (string) app()->makeWith(OgameCampaignConsultationAgent::class, ['request' => $consultationRequest])->instructions(),
    ];

    $machineNames = [
        'metal_mine', 'crystal_mine', 'deuterium_synthesizer', 'solar_plant', 'fusion_reactor',
        'solar_satellite', 'espionage_probe', 'small_cargo', 'large_cargo', 'light_fighter',
    ];

    foreach ($prompts as $prompt) {
        $lower = mb_strtolower($prompt);

        foreach ($machineNames as $machineName) {
            expect($lower)->not->toContain($machineName);
        }

        // A numeric object-id table reads as a run of digits with nothing to name it.
        expect($prompt)->not->toMatch('/\b\d{4,}\b/');
    }
});

test('the campaign consultation prompt reasons like a professional, not a list', function (): void {
    $request = promptGateConsultationRequest();

    $instructions = (string) app()->makeWith(OgameCampaignConsultationAgent::class, ['request' => $request])->instructions();

    expect($instructions)->toContain('develops what the account already owns')
        ->and($instructions)->toContain('easiest unlock before the largest reachable');
});

/** @param list<int> $candidateIds */
function promptGateConsultationRequest(array $candidateIds = []): CampaignConsultationRequest
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
