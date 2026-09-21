<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Operability\AiAuthenticityOverview;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
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

    /**
     * The human reference for interaction-type entropy, measured in Aion over seven types
     * (bot 0.43 vs human 0.84) — plan/details/research/veteran-play.md.
     */
    private const ENTROPY_BASELINE = 0.84;

    public function __construct(
        private readonly AiClock $clock,
        private readonly SessionPlanner $sessionPlanner,
    ) {
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
        $typeCounts = $this->interactionTypes($from, $now);
        $wakeSpread = $this->wakeSpread($now);

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
            'interactionEntropy' => $this->entropy($typeCounts),
            'entropyBaseline' => self::ENTROPY_BASELINE,
            'interactionTypes' => $typeCounts,
            'wakeSpread' => $wakeSpread,
        ]);
    }

    /**
     * The interaction kinds a window actually used, as type names with counts. Shannon entropy
     * over this distribution is the self-similarity figure: a uniform bot answers one type and
     * scores near zero; a human spreads over several kinds (baseline 0.84).
     *
     * @return list<array{type: string, count: int}>
     */
    private function interactionTypes(CarbonImmutable $from, CarbonImmutable $now): array
    {
        return array_values(
            AiSocialExchange::query()
                ->whereBetween('created_at', [$from, $now])
                ->get(['type'])
                ->countBy(static fn (AiSocialExchange $exchange): string => $exchange->type->name)
                ->map(static fn (int $count, string $type): array => ['type' => $type, 'count' => $count])
                ->all(),
        );
    }

    /**
     * @param list<array{type: string, count: int}> $typeCounts
     */
    private function entropy(array $typeCounts): float
    {
        $total = array_sum(array_column($typeCounts, 'count'));

        if ($total === 0) {
            return 0.0;
        }

        $entropy = 0.0;
        foreach ($typeCounts as $row) {
            $probability = $row['count'] / $total;
            $entropy -= $probability * log($probability, 2);
        }

        return round($entropy, 4);
    }

    /**
     * The population's first-awake hour spread: a cohort that all wake in one hour reads as one
     * machine, not many players. The scan is one day of one `isAwake` call per hour per account —
     * bounded and deterministic, never a table read.
     *
     * @return array{spread: int, distinct: int}
     */
    private function wakeSpread(CarbonImmutable $now): array
    {
        $day = $now->startOfDay();
        $firstAwakeHours = [];

        foreach (AiProfile::query()->where('enabled', true)->get(['player_id', 'archetype', 'skill_band', 'random_seed', 'settings']) as $profile) {
            for ($hour = 0; $hour < 24; $hour++) {
                if ($this->sessionPlanner->isAwake($profile, $day->addHours($hour)->addMinutes(30))) {
                    $firstAwakeHours[] = $hour;

                    break;
                }
            }
        }

        if ($firstAwakeHours === []) {
            return ['spread' => 0, 'distinct' => 0];
        }

        return [
            'spread' => max($firstAwakeHours) - min($firstAwakeHours),
            'distinct' => count(array_unique($firstAwakeHours)),
        ];
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
