<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Actions\ExplainAiDecisionAction;
use Modules\AI\Actions\ReplayAiScenarioAction;
use Modules\AI\Actions\SetAiWorkSwitchAction;
use Modules\AI\Actions\SummarizeAiOperabilityAction;
use Modules\AI\Domain\Operability\AiScenarioReplay;
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

    public function index(Request $request): Factory|View
    {
        $this->setBodyId('overview');
        $scenarios = app(ReplayAiScenarioAction::class);
        $requested = $request->query('replay');
        $replay = ['replay' => null, 'name' => null, 'error' => null];

        if (is_string($requested) && $requested !== '') {
            $replay = $this->replay($scenarios, $requested);
        }

        /** @var view-string $view */
        $view = 'ai::index';

        return view($view, [
            'title' => __('t_ai.title'),
            'welcome' => __('t_ai.welcome'),
            'overview' => app(SummarizeAiOperabilityAction::class)->handle(),
            'decisions' => app(ExplainAiDecisionAction::class)->latest(self::RECENT_DECISIONS),
            'scenarios' => $scenarios->names(),
            'replay' => $replay['replay'],
            'replayName' => $replay['name'],
            'replayError' => $replay['error'],
        ]);
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

        app(SetAiWorkSwitchAction::class)->handle(
            (bool) $validated['enabled'],
            $validated['reason'],
            Auth::id(),
        );

        return redirect()->route('ai.index')->with(
            'success',
            $validated['enabled'] ? __('t_ai.switch_resumed') : __('t_ai.switch_stopped_notice'),
        );
    }

    /**
     * A replay is a read, so it is a GET and it writes nothing. A stale or unknown scenario name
     * is reported on the page rather than thrown: the form is a convenience, and an operator
     * looking for something else should not lose the screen.
     *
     * @return array{replay: AiScenarioReplay|null, name: string|null, error: string|null}
     */
    private function replay(ReplayAiScenarioAction $scenarios, string $name): array
    {
        try {
            return ['replay' => $scenarios->handle($scenarios->pathFor($name)), 'name' => $name, 'error' => null];
        } catch (RuntimeException $exception) {
            return ['replay' => null, 'name' => $name, 'error' => $exception->getMessage()];
        }
    }
}
