<?php

namespace Modules\AI\Actions;

use Modules\AI\Support\AiRuntimeSettings;

/**
 * Builds the LLM tab: the provider usage, the monthly wall, the live budget controls the owner
 * edits right here, and the read-only per-day limits and model shape that live in config. One
 * action, one answer; the view renders this and queries nothing.
 */
class BuildAiLlmPanelAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $providers = app(SummarizeAiProviderVisibilityAction::class)->handle();
        $runtime = app(AiRuntimeSettings::class);

        return [
            'providers' => $providers->vendors,
            'configured' => $providers->configured,
            'spent' => $providers->monthToDateCost,
            'ceiling' => $providers->monthlyCeiling,
            'budget' => [
                'monthly_cost_usd' => $runtime->monthlyCostUsd(),
                'language_enabled' => $runtime->languageEnabled(),
                'language_ai_to_ai' => $runtime->languageAiToAi(),
                'campaign_mode' => $runtime->campaignMode()->value,
            ],
            'model' => [
                'provider' => (string) config('ai.language.provider'),
                'model' => (string) config('ai.language.model'),
                'timeout_seconds' => $runtime->languageTimeoutSeconds(),
                'context_characters' => $runtime->languageContextCharacters(),
                'maximum_reply_characters' => $runtime->languageMaximumReplyCharacters(),
                'maximum_input_tokens' => $runtime->languageMaximumInputTokens(),
                'maximum_output_tokens' => $runtime->languageMaximumOutputTokens(),
            ],
            'daily' => [
                'language' => config('ai.language.daily_limits'),
                'campaign' => config('ai.campaign-consultation.daily_limits'),
            ],
        ];
    }
}
