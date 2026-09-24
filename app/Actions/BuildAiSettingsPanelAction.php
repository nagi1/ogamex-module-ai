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
     * The group each live setting renders under, so the page labels the LLM budget controls
     * separately from the population caps and the affect/experience weights.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'profile_cap' => 'population',
        'active_session_cap' => 'population',
        'dispatch_batch_size' => 'population',
        'session_action_cap' => 'population',
        'monthly_cost_usd' => 'llm',
        'language_enabled' => 'llm',
        'language_ai_to_ai' => 'llm',
        'conversation_enabled' => 'llm',
        'conversation_reply_ttl_minutes' => 'llm',
        'campaign_mode' => 'llm',
        'affect_enrichment' => 'feel',
        'affect_decision_weight' => 'feel',
        'experience_decision_weight' => 'feel',
        'review_enabled' => 'review',
    ];

    /**
     * The four-line definition for every live setting, in the field order. `restart` is uniform
     * because a live setting is, by definition, the kind that changes on the next read.
     *
     * @var array<string, array{what: string, why: string, effect: string, restart: string}>
     */
    private const DEFINITIONS = [
        'profile_cap' => ['what' => 'The most accounts that may run at once.', 'why' => 'Raise it to let more accounts play; lower it to shrink the fleet.', 'effect' => '0 = no limit. A new account waits until one stops.', 'restart' => 'No. Picked up on the next read.'],
        'active_session_cap' => ['what' => 'The most sessions that may run at the same time.', 'why' => 'Lower it to stop one account crowding the others with a burst.', 'effect' => '0 = no limit. Work queues until a session frees up.', 'restart' => 'No. Picked up on the next read.'],
        'dispatch_batch_size' => ['what' => 'How many work items one scheduler pass leases and enqueues.', 'why' => 'Lower it to smooth load spikes; raise it to work through a backlog faster.', 'effect' => 'Must be at least 1. Each pass takes at most this many items.', 'restart' => 'No. Picked up on the next read.'],
        'session_action_cap' => ['what' => 'The most game actions one session may queue.', 'why' => 'Lower it to make each account take smaller, slower steps.', 'effect' => '0 = no actions. A session stops after reaching the cap.', 'restart' => 'No. Picked up on the next read.'],
        'monthly_cost_usd' => ['what' => 'The most the paid provider lanes may spend in a month.', 'why' => 'Set it to the budget you are willing to pay.', 'effect' => '0 = off. At the wall the paid lanes stop and accounts fall back to authored replies.', 'restart' => 'No. Picked up on the next read.'],
        'review_enabled' => ['what' => 'Whether the growth and review samples the board reads are recorded.', 'why' => 'Turn off to stop the extra sampling while you investigate.', 'effect' => 'Off = no new growth figures; the existing ones stay.', 'restart' => 'No. Picked up on the next read.'],
        'language_enabled' => ['what' => 'Whether accounts answer player messages through the paid lane.', 'why' => 'Turn off to stop paid replies while you investigate an account.', 'effect' => 'Off = accounts read but never answer; memory and relationships are kept.', 'restart' => 'No. Picked up on the next read.'],
        'language_ai_to_ai' => ['what' => 'Whether a reply between two accounts may reach the paid provider.', 'why' => 'Turn on only when account-to-account chat should use the paid lane.', 'effect' => 'Off = account-to-account replies stay authored and free.', 'restart' => 'No. Picked up on the next read.'],
        'conversation_enabled' => ['what' => 'Whether accounts answer pending messages from other players.', 'why' => 'Turn off to stop replying while you investigate an account.', 'effect' => 'Off = accounts read but never answer; memory and relationships are kept.', 'restart' => 'No. Picked up on the next read.'],
        'conversation_reply_ttl_minutes' => ['what' => 'How long an unanswered message may wait before its reply expires.', 'why' => 'Raise it to give slow accounts longer to answer.', 'effect' => 'A reply older than this is not sent.', 'restart' => 'No. Picked up on the next read.'],
        'affect_enrichment' => ['what' => 'Whether decisions carry the account\'s mood and social stance.', 'why' => 'Turn off to compare decisions without the mood layer.', 'effect' => 'Off = decisions use the plain weights only.', 'restart' => 'No. Picked up on the next read.'],
        'affect_decision_weight' => ['what' => 'How strongly the account\'s mood sways a decision.', 'why' => 'Raise it to make personality count more.', 'effect' => '0 = mood has no effect.', 'restart' => 'No. Picked up on the next read.'],
        'experience_decision_weight' => ['what' => 'How strongly remembered outcomes sway a decision.', 'why' => 'Raise it to make an account repeat what worked before.', 'effect' => '0 = memory has no effect.', 'restart' => 'No. Picked up on the next read.'],
        'campaign_mode' => ['what' => 'Whether campaign consultations may call a provider.', 'why' => 'Use observe to watch the advice, advice to act on it.', 'effect' => 'off = no consultations; observe = read-only; advice = suggestions may be acted on.', 'restart' => 'No. Picked up on the next read.'],
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
            'live' => $this->definitions($this->live($runtime)),
            'deployment' => Yaml::dump($deployment->toArray(), 6, 2),
            'services' => $compose['rows'],
            'up' => $compose['up'],
            'down' => $compose['down'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function definitions(array $rows): array
    {
        return array_map(
            static fn (array $row): array => $row + [
                'definition' => self::DEFINITIONS[$row['field']],
                'group' => self::GROUPS[$row['field']],
            ],
            $rows,
        );
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
