<?php

namespace Modules\AI\Support;

use Modules\AI\Enums\AiCampaignConsultationMode;
use OGame\Services\SettingsService;

/**
 * The module's live, restart-free scalar settings, stored in the host's own settings table.
 *
 * This mirrors the host's `SettingsService` pattern exactly: one typed accessor per setting,
 * reading an `ai_*` key with the reviewed default in code, so a value never written resolves
 * to the baseline and a change takes effect on the next read — no restart, no Docker change.
 * The console's live controls write these keys through the host `SettingsService::set()`.
 *
 * Deployment wiring (drivers, endpoints, worker sizing, schedule cadence) is deliberately
 * absent: that lives in the YAML file behind `AiSettings`. The per-scope daily limits remain
 * in config for now — they are a small structured sub-tree and migrate with the settings
 * screen that edits them.
 */
final class AiRuntimeSettings
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    // -- Population admission -------------------------------------------------------------

    public function profileCap(): int
    {
        return max(0, (int) $this->settings->get('ai_population_profile_cap', 0));
    }

    public function activeSessionCap(): int
    {
        return max(0, (int) $this->settings->get('ai_population_active_session_cap', 0));
    }

    public function dispatchBatchSize(): int
    {
        return max(1, (int) $this->settings->get('ai_population_dispatch_batch_size', 100));
    }

    public function sessionActionCap(): int
    {
        return (int) $this->settings->get('ai_population_session_action_cap', 1);
    }

    // -- Review sampling ------------------------------------------------------------------

    public function reviewEnabled(): bool
    {
        return (bool) $this->settings->get('ai_review_enabled', '1');
    }

    // -- Language lane --------------------------------------------------------------------

    public function languageEnabled(): bool
    {
        return (bool) $this->settings->get('ai_language_enabled', '1');
    }

    public function languageAiToAi(): bool
    {
        return (bool) $this->settings->get('ai_language_ai_to_ai', '0');
    }

    public function languageTimeoutSeconds(): int
    {
        return max(1, (int) $this->settings->get('ai_language_timeout_seconds', 20));
    }

    public function languageReconciliationMinutes(): int
    {
        return max(1, (int) $this->settings->get('ai_language_reconciliation_minutes', 30));
    }

    public function languageContextCharacters(): int
    {
        return max(1, (int) $this->settings->get('ai_language_context_characters', 8_000));
    }

    public function languageMaximumReplyCharacters(): int
    {
        return max(1, (int) $this->settings->get('ai_language_maximum_reply_characters', 1_200));
    }

    public function languageMaximumInputTokens(): int
    {
        return max(1, (int) $this->settings->get('ai_language_maximum_input_tokens', 2_000));
    }

    public function languageMaximumOutputTokens(): int
    {
        return max(1, (int) $this->settings->get('ai_language_maximum_output_tokens', 320));
    }

    // -- Conversation cycle ---------------------------------------------------------------

    public function conversationEnabled(): bool
    {
        return (bool) $this->settings->get('ai_conversation_enabled', '1');
    }

    public function conversationReplyTtlMinutes(): int
    {
        return max(1, (int) $this->settings->get('ai_conversation_reply_ttl_minutes', 180));
    }

    // -- Affect and experience weights ----------------------------------------------------

    public function affectEnrichment(): bool
    {
        return (bool) $this->settings->get('ai_affect_enrichment', '1');
    }

    public function affectDecisionWeight(): int
    {
        return (int) $this->settings->get('ai_affect_decision_weight', 10);
    }

    public function experienceDecisionWeight(): int
    {
        return (int) $this->settings->get('ai_experience_decision_weight', 20);
    }

    // -- Cost wall -------------------------------------------------------------------------

    public function monthlyCostUsd(): float
    {
        return (float) $this->settings->get('ai_monthly_cost_usd', '10');
    }

    // -- Campaign consultation -------------------------------------------------------------

    public function campaignMode(): AiCampaignConsultationMode
    {
        return AiCampaignConsultationMode::tryFrom(
            (string) $this->settings->get('ai_campaign_consultation_mode', AiCampaignConsultationMode::Off->value),
        ) ?? AiCampaignConsultationMode::Off;
    }

    public function campaignTimeoutSeconds(): int
    {
        return max(1, (int) $this->settings->get('ai_campaign_consultation_timeout_seconds', 20));
    }

    public function campaignMaximumReasonCharacters(): int
    {
        return max(1, (int) $this->settings->get('ai_campaign_consultation_maximum_reason_characters', 400));
    }

    public function campaignMaximumEvidenceIds(): int
    {
        return max(1, (int) $this->settings->get('ai_campaign_consultation_maximum_evidence_ids', 8));
    }

    public function campaignMaximumInputTokens(): int
    {
        return max(1, (int) $this->settings->get('ai_campaign_consultation_maximum_input_tokens', 4_000));
    }

    public function campaignMaximumOutputTokens(): int
    {
        return max(1, (int) $this->settings->get('ai_campaign_consultation_maximum_output_tokens', 640));
    }

    public function campaignTriggerCooldownSeconds(): int
    {
        return max(1, (int) $this->settings->get('ai_campaign_consultation_trigger_cooldown_seconds', 3_600));
    }

    public function campaignMaximumAdviceAgeSeconds(): int
    {
        return max(1, (int) $this->settings->get('ai_campaign_consultation_maximum_advice_age_seconds', 900));
    }

    public function campaignMinimumConfidence(): float
    {
        return (float) $this->settings->get('ai_campaign_consultation_minimum_confidence', '0.5');
    }

    public function campaignConcurrencyCap(): int
    {
        return max(1, (int) $this->settings->get('ai_campaign_consultation_concurrency_cap', 1));
    }

    public function campaignReconciliationMinutes(): int
    {
        return max(1, (int) $this->settings->get('ai_campaign_consultation_reconciliation_minutes', 30));
    }
}
