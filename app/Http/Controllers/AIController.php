<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Modules\AI\Actions\BuildAiAuthenticityPanelAction;
use Modules\AI\Actions\BuildAiCampaignControlAction;
use Modules\AI\Actions\BuildAiLlmPanelAction;
use Modules\AI\Actions\BuildAiOperationsPanelAction;
use Modules\AI\Actions\BuildAiPilotReportAction;
use Modules\AI\Actions\BuildAiPlayerRosterAction;
use Modules\AI\Actions\BuildAiSettingsPanelAction;
use Modules\AI\Actions\BuildAiSituationPanelAction;
use Modules\AI\Actions\ExplainAiDecisionAction;
use Modules\AI\Actions\RunAiCampaignControlAction;
use Modules\AI\Actions\RunAiOperationAction;
use Modules\AI\Actions\SetAiAccountEnabledAction;
use Modules\AI\Actions\SetAiWorkSwitchAction;
use Modules\AI\Actions\SummarizeAiLivenessAction;
use Modules\AI\Actions\SummarizeAiOperabilityAction;
use Modules\AI\Actions\SummarizeAiProviderVisibilityAction;
use Modules\AI\Actions\SummarizeAiStorageHealthAction;
use Modules\AI\Enums\AiCampaignControl;
use Modules\AI\Enums\AiOperation;
use Modules\AI\Models\AiProfile;
use OGame\Http\Controllers\OGameController;
use OGame\Services\SettingsService;

/**
 * The module's operator page.
 *
 * It reads what the module is doing, explains recorded decisions and can start or stop new work;
 * it never makes a decision for an account and never writes game state, so the page an operator
 * uses to slow the population down is not itself a way to change the game. The one state change
 * on the page is the switch, which is a POST.
 */
class AIController extends OGameController
{
    /** Enough decisions to see a pattern, few enough to read on one screen. */
    private const RECENT_DECISIONS = 5;

    /**
     * The six sections of the operator console, ordered by how often the owner asks the question.
     * Health is the one place "is it working, is it playing well, what does it cost" is read.
     */
    private const TABS = ['health', 'llm', 'players', 'settings', 'operations', 'campaigns'];

    /**
     * The windows an operator may ask the pilot report for. A free number would let one page view
     * become an unbounded scan of the trace history, so the selector offers the three a review
     * actually compares: today, a week and a month.
     */
    private const PILOT_WINDOWS = [1, 7, 30];

    public function index(Request $request): Factory|View
    {
        $this->setBodyId('overview');
        $tab = $this->tab($request);
        $days = $this->pilotDays($request);
        $health = $tab === 'health';

        // The Health tab is the whole situation dashboard: the overview, the pilot window, the
        // five-question situation, liveness, storage, providers and authenticity are all read in
        // one pass, so the heavy report is paid only when the operator asks for the dashboard.
        $overview = $health
            ? app(SummarizeAiOperabilityAction::class)->handle()
            : null;
        $pilot = $health
            ? app(BuildAiPilotReportAction::class)->handle($days)->toArray()
            : null;
        $liveness = $health
            ? app(SummarizeAiLivenessAction::class)->handle()
            : null;
        $storage = $health
            ? app(SummarizeAiStorageHealthAction::class)->handle()
            : null;
        $providers = $health
            ? app(SummarizeAiProviderVisibilityAction::class)->handle()
            : null;
        $authenticity = $health
            ? app(BuildAiAuthenticityPanelAction::class)->handle($days)
            : null;
        $situation = $health
            ? app(BuildAiSituationPanelAction::class)->handle($days, $authenticity)
            : null;
        $roster = $tab === 'players'
            ? app(BuildAiPlayerRosterAction::class)->handle(
                $days,
                trim((string) $request->query('search', '')),
                in_array($request->query('state'), ['enabled', 'stopped'], true) ? $request->query('state') : 'all',
                $request->boolean('alerts'),
            )
            : null;
        if ($roster !== null) {
            $impersonate = app('impersonate');
            $roster['impersonating'] = $impersonate->isImpersonating();
            $roster['impersonated_username'] = $impersonate->isImpersonating() ? (Auth::user()?->username ?? null) : null;
            $roster['impersonate_leave_url'] = $impersonate->isImpersonating() ? route('impersonate.leave') : null;
        }
        $settingsPanel = $tab === 'settings'
            ? app(BuildAiSettingsPanelAction::class)->handle()
            : null;
        $operations = $tab === 'operations'
            ? app(BuildAiOperationsPanelAction::class)->handle()
            : null;
        $campaigns = $tab === 'campaigns'
            ? app(BuildAiCampaignControlAction::class)->handle()
            : null;
        $llm = $tab === 'llm'
            ? app(BuildAiLlmPanelAction::class)->handle()
            : null;

        /** @var view-string $view */
        $view = 'ai::index';

        return view($view, [
            'title' => __('t_ai.title'),
            'tab' => $tab,
            'overview' => $overview,
            // The page shows the report's own answer rather than a second reading of the same
            // tables, so the page and `ai:pilot-report` can never disagree about a window.
            'pilot' => $pilot,
            'pilotDays' => $days,
            'llm' => $llm,
            'liveness' => $liveness,
            'storage' => $storage,
            'providers' => $providers,
            'situation' => $situation,
            'roster' => $roster,
            'authenticity' => $authenticity,
            'settingsPanel' => $settingsPanel,
            'operations' => $operations,
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * The requested window, or one day when it is not one of the offered ones. An unknown value is
     * answered with the default rather than an error: the selector is a convenience, and an operator
     * who mistypes a URL should still get the page.
     */
    private function pilotDays(Request $request): int
    {
        $requested = $request->query('days');
        $days = is_numeric($requested) ? (int) $requested : 1;

        return in_array($days, self::PILOT_WINDOWS, true) ? $days : 1;
    }

    /**
     * Stops or resumes new AI work. Work already in flight finishes, because stopping a session
     * midway would leave a half-queued action; the switch governs what starts next.
     */
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $actorId = Auth::id();

        app(SetAiWorkSwitchAction::class)->handle(
            (bool) $validated['enabled'],
            $validated['reason'],
            $actorId === null ? null : (int) $actorId,
        );

        return redirect()->route('ai.index')->with(
            'success',
            $validated['enabled'] ? __('t_ai.switch_resumed') : __('t_ai.switch_stopped_notice'),
        );
    }

    /**
     * Stops or resumes one account, not the population. A stopped account keeps its memory,
     * relationships and obligations; it just stops taking new work and stops answering, which
     * is why the reason is recorded with the account rather than thrown away.
     */
    public function switchAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'player_id' => ['required', 'integer'],
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $actorId = Auth::id();

        app(SetAiAccountEnabledAction::class)->handle(
            (int) $validated['player_id'],
            (bool) $validated['enabled'],
            $validated['reason'],
            $actorId === null ? null : (int) $actorId,
        );

        return redirect()->route('ai.index', ['tab' => 'players'])->with(
            'success',
            $validated['enabled'] ? __('t_ai.account_resumed') : __('t_ai.account_stopped'),
        );
    }

    /**
     * Queues one console operation and returns immediately. The command runs on the AI lane, so
     * a click never executes artisan inside the request, and the run is audited.
     */
    public function operations(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'operation' => ['required', Rule::enum(AiOperation::class)],
        ]);

        $operation = AiOperation::from($validated['operation']);
        $actorId = Auth::id();

        app(RunAiOperationAction::class)->handle(
            $operation,
            $actorId === null ? null : (int) $actorId,
        );

        return redirect()->route('ai.index', ['tab' => 'operations'])->with(
            'success',
            __('t_ai.operation_queued', ['operation' => __('t_ai.operation_'.$operation->value)]),
        );
    }

    /**
     * Queues one campaign control and returns immediately. Opening and declaring validate their
     * inputs here; the three no-input controls queue straight through. Every control runs on the
     * AI lane and is audited, so a click never mutates a campaign inside the request.
     */
    public function campaigns(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'control' => ['required', Rule::enum(AiCampaignControl::class)],
        ]);

        $control = AiCampaignControl::from($validated['control']);
        $actorId = Auth::id();

        app(RunAiCampaignControlAction::class)->handle(
            $control,
            $this->campaignParams($control, $request),
            $actorId === null ? null : (int) $actorId,
        );

        return redirect()->route('ai.index', ['tab' => 'campaigns'])->with(
            'success',
            __('t_ai.campaign_control_queued', ['control' => __('t_ai.'.$this->campaignControlLabel($control))]),
        );
    }

    private function campaignControlLabel(AiCampaignControl $control): string
    {
        return 'campaign_control_'.str_replace('-', '_', substr($control->value, strlen('campaign:')));
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignParams(AiCampaignControl $control, Request $request): array
    {
        return match ($control) {
            AiCampaignControl::Open => $request->validate([
                'starts_at' => ['required', 'date'],
                'ends_at' => ['required', 'date', 'after:starts_at'],
            ]),
            AiCampaignControl::Declare => array_map(
                'intval',
                $request->validate([
                    'campaign_id' => ['required', 'integer', 'exists:ai_campaigns,id'],
                    'planet_id' => ['required', 'integer', 'exists:planets,id'],
                ]),
            ),
            default => [],
        };
    }

    /**
     * Writes the live settings through the host's own settings table. The values are cast here
     * rather than trusted from the request, and the deployment half (the YAML file) is never
     * touched by a web request — the owner composes it and copies it out.
     */
    public function settings(Request $request): RedirectResponse
    {
        $request->validate([
            'profile_cap' => ['required', 'integer', 'min:0'],
            'active_session_cap' => ['required', 'integer', 'min:0'],
            'dispatch_batch_size' => ['required', 'integer', 'min:1'],
            'session_action_cap' => ['required', 'integer', 'min:0'],
            'monthly_cost_usd' => ['required', 'numeric', 'min:0'],
            'conversation_reply_ttl_minutes' => ['required', 'integer', 'min:1'],
            'affect_decision_weight' => ['required', 'integer', 'min:0'],
            'experience_decision_weight' => ['required', 'integer', 'min:0'],
            'campaign_mode' => ['required', 'in:off,observe,advice'],
        ]);

        $settings = app(SettingsService::class);

        foreach (BuildAiSettingsPanelAction::LIVE_SETTINGS as $field => $meta) {
            $settings->set($meta['key'], $this->castSetting($meta['type'], $request->input($field), $request->boolean($field)));
        }

        return redirect()->route('ai.index', ['tab' => 'settings'])->with('success', __('t_ai.settings_saved'));
    }

    /**
     * Writes the LLM budget controls through the host settings table: the monthly wall and the
     * three lane toggles. The per-day limits and the model stay in config; they are read-only here.
     */
    public function llm(Request $request): RedirectResponse
    {
        $request->validate([
            'monthly_cost_usd' => ['required', 'numeric', 'min:0'],
            'campaign_mode' => ['required', 'in:off,observe,advice'],
        ]);

        $settings = app(SettingsService::class);

        $settings->set('ai_monthly_cost_usd', $this->castSetting('float', $request->input('monthly_cost_usd'), false));
        $settings->set('ai_language_enabled', $this->castSetting('bool', '1', $request->boolean('language_enabled')));
        $settings->set('ai_language_ai_to_ai', $this->castSetting('bool', '1', $request->boolean('language_ai_to_ai')));
        $settings->set('ai_campaign_consultation_mode', (string) $request->input('campaign_mode'));

        return redirect()->route('ai.index', ['tab' => 'llm'])->with('success', __('t_ai.settings_saved'));
    }

    private function castSetting(string $type, mixed $value, bool $checked): string
    {
        return match ($type) {
            'bool' => $checked ? '1' : '0',
            'int' => (string) max(0, (int) $value),
            'float' => (string) max(0, (float) $value),
            default => (string) $value,
        };
    }

    /**
     * One account's decisions and window growth, the drill-down the board links to. Nothing here
     * writes; it is the same traces and the same score loop the rest of the console already reads,
     * scoped to one account so an operator can tell a broken account from a broken plan.
     */
    public function account(int $player): Factory|View
    {
        $this->setBodyId('overview');

        $profile = AiProfile::query()->where('player_id', $player)->firstOrFail();
        $decisions = app(ExplainAiDecisionAction::class)->forPlayer($player, self::RECENT_DECISIONS);
        $delta = collect(app(BuildAiPilotReportAction::class)->scoreFor(7)->perAccountDeltas)
            ->firstWhere('player_id', $player)['delta'] ?? null;

        /** @var view-string $view */
        $view = 'ai::account';

        return view($view, [
            'title' => __('t_ai.account_title', ['player' => $player]),
            'profile' => $profile,
            'decisions' => $decisions,
            'delta' => $delta,
        ]);
    }

    /**
     * The requested tab, or the overview when the value is not one of the offered ones.
     */
    private function tab(Request $request): string
    {
        $requested = $request->query('tab');

        return is_string($requested) && in_array($requested, self::TABS, true) ? $requested : 'health';
    }
}
