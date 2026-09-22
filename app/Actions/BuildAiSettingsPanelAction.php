<?php

namespace Modules\AI\Actions;

use Modules\AI\Support\AiRuntimeSettings;
use Modules\AI\Support\AiSettings;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the Settings tab: the live, restart-free guardrails the operator edits on the page, and
 * the deployment wiring the server owner edits in the YAML file.
 *
 * One action, one answer: the view renders this and queries nothing. The live list is the same
 * structure the controller writes through the host SettingsService, so the definition of a live
 * setting exists exactly once.
 */
class BuildAiSettingsPanelAction
{
    /**
     * The live settings, in the order the page shows them. `field` is the form field and the lang
     * key suffix; `key` is the host settings-table key; `type` decides the control and the cast.
     *
     * @var array<string, array{key: string, type: string}>
     */
    public const LIVE_SETTINGS = [
        'profile_cap' => ['key' => 'ai_population_profile_cap', 'type' => 'int'],
        'active_session_cap' => ['key' => 'ai_population_active_session_cap', 'type' => 'int'],
        'dispatch_batch_size' => ['key' => 'ai_population_dispatch_batch_size', 'type' => 'int'],
        'session_action_cap' => ['key' => 'ai_population_session_action_cap', 'type' => 'int'],
        'monthly_cost_usd' => ['key' => 'ai_monthly_cost_usd', 'type' => 'float'],
        'review_enabled' => ['key' => 'ai_review_enabled', 'type' => 'bool'],
        'language_enabled' => ['key' => 'ai_language_enabled', 'type' => 'bool'],
        'language_ai_to_ai' => ['key' => 'ai_language_ai_to_ai', 'type' => 'bool'],
        'conversation_enabled' => ['key' => 'ai_conversation_enabled', 'type' => 'bool'],
        'conversation_reply_ttl_minutes' => ['key' => 'ai_conversation_reply_ttl_minutes', 'type' => 'int'],
        'affect_enrichment' => ['key' => 'ai_affect_enrichment', 'type' => 'bool'],
        'affect_decision_weight' => ['key' => 'ai_affect_decision_weight', 'type' => 'int'],
        'experience_decision_weight' => ['key' => 'ai_experience_decision_weight', 'type' => 'int'],
        'campaign_mode' => ['key' => 'ai_campaign_consultation_mode', 'type' => 'select'],
    ];

    /**
     * @return array{live: list<array<string, mixed>>, deployment: string, services: list<array<string, mixed>>, up: ?string, down: ?string}
     */
    public function handle(): array
    {
        $runtime = app(AiRuntimeSettings::class);
        $deployment = app(AiSettings::class);
        $compose = $this->compose($deployment);

        return [
            'live' => $this->live($runtime),
            'deployment' => Yaml::dump($deployment->toArray(), 6, 2),
            'services' => $compose['rows'],
            'up' => $compose['up'],
            'down' => $compose['down'],
        ];
    }

    /**
     * The sidecar matrix and the up/down commands, derived from the same driver selects that
     * decide which service answers. A service is up when its select is chosen and the mode is
     * not native; the stop command names every other service. The commands are generated from
     * one object so they can never disagree with the matrix.
     *
     * @return array{rows: list<array{service: string, job: string, selects: string, needed: bool}>, up: ?string, down: ?string}
     */
    private function compose(AiSettings $settings): array
    {
        $external = $settings->get('drivers.mode') !== 'native';

        $needed = [
            'fatima' => $external && $settings->get('drivers.cognition') === 'fatima',
            'psychsim' => $external && $settings->get('drivers.cognition') === 'psychsim',
            'cbrkit' => $external && $settings->get('drivers.experience') === 'cbrkit',
            'agentos' => $external && $settings->get('drivers.memory') === 'agentos',
        ];

        $up = array_keys(array_filter($needed));
        $down = array_keys(array_filter($needed, static fn (bool $needed): bool => !$needed));
        $command = 'docker compose -f Modules/AI/docker/cognition/docker-compose.yml';

        return [
            'rows' => [
                ['service' => 'fatima', 'job' => 'Appraisal and social', 'selects' => 'cognition = fatima', 'needed' => $needed['fatima']],
                ['service' => 'psychsim', 'job' => 'Theory-of-mind stance', 'selects' => 'cognition = psychsim', 'needed' => $needed['psychsim']],
                ['service' => 'cbrkit', 'job' => 'Structured case recall', 'selects' => 'experience = cbrkit', 'needed' => $needed['cbrkit']],
                ['service' => 'agentos', 'job' => 'Ranked memory', 'selects' => 'memory = agentos', 'needed' => $needed['agentos']],
            ],
            'up' => $up === [] ? null : $command.' up -d '.implode(' ', $up),
            'down' => $down === [] ? null : $command.' stop '.implode(' ', $down),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function live(AiRuntimeSettings $runtime): array
    {
        return [
            $this->int('profile_cap', $runtime->profileCap(), 0),
            $this->int('active_session_cap', $runtime->activeSessionCap(), 0),
            $this->int('dispatch_batch_size', $runtime->dispatchBatchSize(), 100),
            $this->int('session_action_cap', $runtime->sessionActionCap(), 1),
            $this->number('monthly_cost_usd', $runtime->monthlyCostUsd(), 10),
            $this->bool('review_enabled', $runtime->reviewEnabled(), true),
            $this->bool('language_enabled', $runtime->languageEnabled(), true),
            $this->bool('language_ai_to_ai', $runtime->languageAiToAi(), false),
            $this->bool('conversation_enabled', $runtime->conversationEnabled(), true),
            $this->int('conversation_reply_ttl_minutes', $runtime->conversationReplyTtlMinutes(), 180),
            $this->bool('affect_enrichment', $runtime->affectEnrichment(), true),
            $this->int('affect_decision_weight', $runtime->affectDecisionWeight(), 10),
            $this->int('experience_decision_weight', $runtime->experienceDecisionWeight(), 20),
            ['field' => 'campaign_mode', 'type' => 'select', 'value' => $runtime->campaignMode()->value, 'default' => 'off', 'options' => ['off', 'observe', 'advice']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function int(string $field, int $value, int $default): array
    {
        return ['field' => $field, 'type' => 'int', 'value' => $value, 'default' => $default];
    }

    /**
     * @return array<string, mixed>
     */
    private function number(string $field, float $value, float $default): array
    {
        return ['field' => $field, 'type' => 'float', 'value' => $value, 'default' => $default];
    }

    /**
     * @return array<string, mixed>
     */
    private function bool(string $field, bool $value, bool $default): array
    {
        return ['field' => $field, 'type' => 'bool', 'value' => $value, 'default' => $default];
    }
}
