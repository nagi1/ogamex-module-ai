<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Actions\SetAiWorkSwitchAction;
use Modules\AI\Actions\SummarizeAiOperabilityAction;
use OGame\Http\Controllers\OGameController;

/**
 * The module's operator page.
 *
 * It reads what the module is doing and can start or stop new work; it never makes a decision
 * for an account and never writes game state, so the page an operator uses to slow the
 * population down is not itself a way to change the game.
 */
class AIController extends OGameController
{
    public function index(): Factory|View
    {
        $this->setBodyId('overview');
        /** @var view-string $view */
        $view = 'ai::index';

        return view($view, [
            'title' => __('t_ai.title'),
            'welcome' => __('t_ai.welcome'),
            'overview' => app(SummarizeAiOperabilityAction::class)->handle(),
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
}
