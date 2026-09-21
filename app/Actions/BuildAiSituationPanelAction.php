<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Operability\AiSituationOverview;

/**
 * The situation dashboard: the review loop's five questions, each answered with the figure a
 * reviewer reads and its evidence class. It composes the figures the authenticity and liveness
 * panels already compute — it never recomputes a table — and adds the one thing those panels do
 * not carry: the question framing and the evidence class the review record requires.
 */
class BuildAiSituationPanelAction
{
    private const MEASURED = 'measured';

    public function handle(int $days): AiSituationOverview
    {
        $window = max(1, $days);
        $authenticity = app(BuildAiAuthenticityPanelAction::class)->handle($window);
        $liveness = app(SummarizeAiLivenessAction::class)->handle();
        $saveStates = $authenticity->saveOutcomes === []
            ? '—'
            : implode(', ', array_map(static fn (string $state, int $count): string => $count . ' ' . $state, array_keys($authenticity->saveOutcomes), $authenticity->saveOutcomes));

        $questions = [
            [
                'question' => 't_ai.situation_q_capability',
                'figure' => sprintf('median +%s/day, %d accounts flat', (string) $authenticity->growth['median_delta'], $authenticity->growth['zero_growth_accounts']),
                'evidence' => self::MEASURED,
                'window' => $window,
            ],
            [
                'question' => 't_ai.situation_q_growth',
                'figure' => sprintf('largest hourly jump +%s', (string) $authenticity->growth['largest_hourly_jump']),
                'evidence' => self::MEASURED,
                'window' => $window,
            ],
            [
                'question' => 't_ai.situation_q_divergence',
                'figure' => sprintf('entropy %s vs 0.84, %d distinct reasons', (string) $authenticity->interactionEntropy, $authenticity->distinctReasons),
                'evidence' => self::MEASURED,
                'window' => $window,
            ],
            [
                'question' => 't_ai.situation_q_reaction',
                'figure' => sprintf('%d in window / %d out, save states: %s', $authenticity->reactionsInsideWindow, $authenticity->reactionsOutsideWindow, $saveStates),
                'evidence' => self::MEASURED,
                'window' => $window,
            ],
            [
                'question' => 't_ai.situation_q_aliveness',
                'figure' => sprintf('%d distinct contacts, last activity %s', $authenticity->distinctContacts, $liveness->lastActivityAt ?? '—'),
                'evidence' => self::MEASURED,
                'window' => $window,
            ],
        ];

        return app()->makeWith(AiSituationOverview::class, ['days' => $window, 'questions' => $questions]);
    }
}
