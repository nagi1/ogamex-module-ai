<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Actions\BuildAiAuthenticityPanelAction;
use Modules\AI\Actions\BuildAiPilotReportAction;
use Modules\AI\Actions\BuildAiProgressBoardAction;
use Modules\AI\Actions\BuildAiSituationPanelAction;
use Modules\AI\Actions\ExplainAiDecisionAction;
use Modules\AI\Actions\ReplayAiScenarioAction;
use Modules\AI\Actions\SetAiAccountEnabledAction;
use Modules\AI\Actions\SetAiWorkSwitchAction;
use Modules\AI\Actions\SummarizeAiLivenessAction;
use Modules\AI\Actions\SummarizeAiOperabilityAction;
use Modules\AI\Actions\SummarizeAiProviderVisibilityAction;
use Modules\AI\Actions\SummarizeAiStorageHealthAction;
use Modules\AI\Domain\Operability\AiScenarioReplay;
use Modules\AI\Models\AiProfile;
use OGame\Http\Controllers\OGameController;
use RuntimeException;

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
     * The three views an operator switches between. Splitting the page mirrors the host's
     * activity-log tabs and keeps the heavy windowed report off the default load, so the page an
     * operator opens first stays fast.
     */
    private const TABS = ['overview', 'pilot', 'decisions', 'monitoring', 'accounts'];

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

        // Each tab computes only what it renders: the pilot report scans the score history, so it
        // is never paid for when an operator only wants today's overview.
        $pilot = $tab === 'pilot'
            ? app(BuildAiPilotReportAction::class)->handle($days)->toArray()
            : null;
        $decisions = $tab === 'decisions'
            ? app(ExplainAiDecisionAction::class)->latest(self::RECENT_DECISIONS)
            : [];
        $overview = $tab === 'overview'
            ? app(SummarizeAiOperabilityAction::class)->handle()
            : null;
        $liveness = $tab === 'monitoring'
            ? app(SummarizeAiLivenessAction::class)->handle()
            : null;
        $storage = $tab === 'monitoring'
            ? app(SummarizeAiStorageHealthAction::class)->handle()
            : null;
        $providers = $tab === 'monitoring'
            ? app(SummarizeAiProviderVisibilityAction::class)->handle()
            : null;
        $situation = $tab === 'monitoring'
            ? app(BuildAiSituationPanelAction::class)->handle($days)
            : null;
        $profiles = $tab === 'monitoring'
            ? AiProfile::query()->orderBy('player_id')->get(['player_id', 'archetype', 'skill_band', 'enabled'])
            : collect();
        $board = $tab === 'accounts'
            ? app(BuildAiProgressBoardAction::class)->handle($days)
            : null;
        $authenticity = $tab === 'accounts'
            ? app(BuildAiAuthenticityPanelAction::class)->handle($days)
            : null;
        $replay = $this->replayRequest($request);

        /** @var view-string $view */
        $view = 'ai::index';

        return view($view, [
            'title' => __('t_ai.title'),
            'welcome' => __('t_ai.welcome'),
            'tab' => $tab,
            'overview' => $overview,
            // The page shows the report's own answer rather than a second reading of the same
            // tables, so the page and `ai:pilot-report` can never disagree about a window.
            'pilot' => $pilot,
            'pilotDays' => $days,
            'decisions' => $decisions,
            'scenarios' => $tab === 'decisions' ? app(ReplayAiScenarioAction::class)->names() : [],
            'replay' => $replay['replay'],
            'replayName' => $replay['name'],
            'replayError' => $replay['error'],
            'liveness' => $liveness,
            'storage' => $storage,
            'providers' => $providers,
            'situation' => $situation,
            'profiles' => $profiles,
            'board' => $board,
            'authenticity' => $authenticity,
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

        return redirect()->route('ai.index', ['tab' => 'monitoring'])->with(
            'success',
            $validated['enabled'] ? __('t_ai.account_resumed') : __('t_ai.account_stopped'),
        );
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
     * The requested tab, or the overview when the value is not one of the offered ones. A replay
     * URL is a decisions-tab URL even when the tab is not stated, so a pasted replay link lands
     * where its answer is shown.
     */
    private function tab(Request $request): string
    {
        if ($request->query('replay') !== null) {
            return 'decisions';
        }

        $requested = $request->query('tab');

        return is_string($requested) && in_array($requested, self::TABS, true) ? $requested : 'overview';
    }

    /**
     * A replay is a read, so it is a GET and it writes nothing. A stale or unknown scenario name
     * is reported on the page rather than thrown: the form is a convenience, and an operator
     * looking for something else should not lose the screen.
     *
     * @return array{replay: AiScenarioReplay|null, name: string|null, error: string|null}
     */
    private function replayRequest(Request $request): array
    {
        $requested = $request->query('replay');

        if (!is_string($requested) || $requested === '') {
            return ['replay' => null, 'name' => null, 'error' => null];
        }

        $scenarios = app(ReplayAiScenarioAction::class);

        try {
            return ['replay' => $scenarios->handle($scenarios->pathFor($requested)), 'name' => $requested, 'error' => null];
        } catch (RuntimeException $exception) {
            return ['replay' => null, 'name' => $requested, 'error' => $exception->getMessage()];
        }
    }
}
