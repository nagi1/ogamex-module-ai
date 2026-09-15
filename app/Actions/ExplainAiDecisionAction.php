<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\AI\Domain\Operability\AiDecisionExplanation;
use Modules\AI\Models\AiDecisionTrace;

/**
 * Reads recorded decisions back in a form an operator can judge.
 *
 * A trace is the module's own account of why an account did something, so explaining one is a
 * read of stored fields rather than a new decision: the explanation never re-runs the policy,
 * which is what keeps it true to what actually happened. Candidates are listed by action and
 * score only; the parameters they carried stay in the trace.
 */
class ExplainAiDecisionAction
{
    /** Enough to see what lost, without turning the page into a dump of every candidate. */
    private const MAXIMUM_ALTERNATIVES = 6;

    public function forTrace(int $traceId): AiDecisionExplanation|null
    {
        $trace = AiDecisionTrace::query()->find($traceId);

        return $trace === null ? null : $this->explain($trace);
    }

    /**
     * @return array<int, AiDecisionExplanation>
     */
    public function forPlayer(int $playerId, int $limit = 5): array
    {
        return $this->recent(AiDecisionTrace::query()->where('player_id', $playerId), $limit);
    }

    /**
     * The universe view, which is what a pilot check starts from: the newest decisions across
     * every account, so an operator does not have to guess which account to look at.
     *
     * @return array<int, AiDecisionExplanation>
     */
    public function latest(int $limit = 5): array
    {
        return $this->recent(AiDecisionTrace::query(), $limit);
    }

    /**
     * @param Builder<AiDecisionTrace> $query
     * @return array<int, AiDecisionExplanation>
     */
    private function recent(Builder $query, int $limit): array
    {
        return $query
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (AiDecisionTrace $trace): AiDecisionExplanation => $this->explain($trace))
            ->values()
            ->all();
    }

    private function explain(AiDecisionTrace $trace): AiDecisionExplanation
    {
        $recorded = $trace->score_components;
        $refusals = $recorded['rejections'] ?? null;
        unset($recorded['rejections']);

        return app()->makeWith(AiDecisionExplanation::class, [
            'traceId' => (int) $trace->id,
            'playerId' => (int) $trace->player_id,
            'observedAt' => CarbonImmutable::instance($trace->observed_at),
            'selectedAction' => $trace->selected_action->name,
            'selectedReason' => (string) $trace->selected_reason,
            'selectedScore' => $this->scoreOf($trace, $trace->selected_action->name),
            'components' => $this->numeric($recorded),
            'refusals' => is_array($refusals) ? $this->labels($refusals) : [],
            'alternatives' => $this->alternatives($trace),
            'evidence' => $this->labels($trace->source_timestamps),
        ]);
    }

    /**
     * @return array<int, array{action: string, score: float}>
     */
    private function alternatives(AiDecisionTrace $trace): array
    {
        return collect($trace->candidates)
            ->map(static fn (array $candidate): array => [
                'action' => (string) ($candidate['action'] ?? ''),
                'score' => (float) ($candidate['score'] ?? 0.0),
            ])
            ->sortByDesc('score')
            ->take(self::MAXIMUM_ALTERNATIVES)
            ->values()
            ->all();
    }

    private function scoreOf(AiDecisionTrace $trace, string $action): float
    {
        foreach ($trace->candidates as $candidate) {
            if (($candidate['action'] ?? null) === $action) {
                return (float) ($candidate['score'] ?? 0.0);
            }
        }

        return 0.0;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, float>
     */
    private function numeric(array $values): array
    {
        $numeric = [];

        foreach ($values as $key => $value) {
            if (!is_int($value) && !is_float($value)) {
                continue;
            }

            $numeric[(string) $key] = (float) $value;
        }

        return $numeric;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, string>
     */
    private function labels(array $values): array
    {
        $labels = [];

        foreach ($values as $key => $value) {
            $labels[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $labels;
    }
}
