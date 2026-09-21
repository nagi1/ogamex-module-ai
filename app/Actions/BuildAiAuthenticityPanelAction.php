<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Operability\AiAuthenticityOverview;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\AiClock;

/**
 * The one surface that can answer whether the accounts are distinguishable: reaction delay after
 * a hostile observation, save refusals, the growth curve and behavioural breadth. Every figure
 * reads rows that already exist; a full scan means a missing counter, not a query.
 */
class BuildAiAuthenticityPanelAction
{
    /** PlayerObservationService plans the reaction inside this many seconds. */
    private const REACTION_WINDOW_MIN_SECONDS = 120;
    private const REACTION_WINDOW_MAX_SECONDS = 180;

    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $days): AiAuthenticityOverview
    {
        $window = max(1, $days);
        $now = $this->clock->now();
        $from = $now->subDays($window);

        $observations = AiObservation::query()
            ->where('kind', AiObservationKind::BattleReportObserved)
            ->whereBetween('observed_at', [$from, $now])
            ->orderBy('observed_at')
            ->get(['player_id', 'observed_at']);

        $tracesByPlayer = AiDecisionTrace::query()
            ->whereBetween('observed_at', [$from, $now])
            ->orderBy('observed_at')
            ->get(['player_id', 'observed_at'])
            ->groupBy('player_id');

        // ponytail: each observation scans its own account's traces once; a hostile universe with
        // many observations per account could reach O(observations × traces/account). Upgrade path:
        // a per-account next-decision cursor written at session time, if this ever shows up in the
        // panel's own read cost.
        $inside = 0;
        $outside = 0;

        foreach ($observations as $observation) {
            $next = $tracesByPlayer->get($observation->player_id, collect())
                ->first(fn (AiDecisionTrace $trace): bool => $trace->observed_at >= $observation->observed_at);

            if ($next === null) {
                $outside++;

                continue;
            }

            $seconds = $observation->observed_at->diffInSeconds($next->observed_at);
            $seconds >= self::REACTION_WINDOW_MIN_SECONDS && $seconds <= self::REACTION_WINDOW_MAX_SECONDS ? $inside++ : $outside++;
        }

        $score = app(BuildAiPilotReportAction::class)->scoreFor($window);

        return app()->makeWith(AiAuthenticityOverview::class, [
            'reactionObservations' => $observations->count(),
            'reactionsInsideWindow' => $inside,
            'reactionsOutsideWindow' => $outside,
            'saveOutcomes' => $this->saveOutcomes($from, $now),
            'growth' => [
                'median_delta' => $score->generalDeltaMedian,
                'spread' => $score->generalDeltaMax - $score->generalDeltaMin,
                'largest_hourly_jump' => $score->largestHourlyJump,
                'zero_growth_accounts' => $score->zeroGrowthAccounts,
            ],
            'distinctReasons' => AiDecisionTrace::query()->whereBetween('observed_at', [$from, $now])->distinct()->count('selected_reason'),
            'distinctContacts' => AiSocialExchange::query()->whereBetween('created_at', [$from, $now])->distinct()->count('counterparty_player_id'),
        ]);
    }

    /**
     * Save refusals, never a success rate: a 100 % save rate is itself the finding, so the panel
     * shows the states that broke the streak rather than a number that hides them.
     *
     * @return array<string, int>
     */
    private function saveOutcomes(CarbonImmutable $from, CarbonImmutable $now): array
    {
        return AiActionReceipt::query()
            ->whereIn('action_type', [AiActionType::DispatchFleet, AiActionType::RecallFleet])
            ->whereBetween('created_at', [$from, $now])
            ->pluck('state')
            ->countBy(static fn (AiReceiptState $state): string => $state->name)
            ->all();
    }
}
