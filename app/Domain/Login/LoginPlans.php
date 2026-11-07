<?php

namespace Modules\AI\Domain\Login;

use Closure;

/**
 * The plans the engine read while deciding this login's errand, kept for the errand encoder.
 *
 * The wide errand encoder prices each engine candidate from the plan its planner returns, and the engine had just
 * asked the same planners the same question on the same state: asking again doubled the decision's cost (measured
 * 6 Nov 2026 on a late universe: the planners were ~80% of the encoder's 220 ms). The engine keeps each answer and the
 * encoder reuses it; nothing else reads it, so the engine's own path is unchanged. The container scopes it to one
 * decision: `reset()` is called when the login's decision starts and when the errand is decided.
 */
class LoginPlans
{
    /** @var array<string, mixed> */
    private array $plans = [];

    public function reset(): void
    {
        $this->plans = [];
    }

    /**
     * Keeps the engine's answer to a planner question and hands it back unchanged.
     *
     * @param string $planner the planner class (`::method` appended when the question is not its `plan`)
     * @param list<int|null> $arguments the question's scalar arguments
     */
    public function keep(string $planner, array $arguments, mixed $plan): mixed
    {
        $this->plans[$this->question($planner, $arguments)] = $plan;

        return $plan;
    }

    /**
     * The engine's answer to this question when it asked it this decision, else a fresh one.
     *
     * @param list<int|null> $arguments
     */
    public function reuse(string $planner, array $arguments, Closure $plan): mixed
    {
        $question = $this->question($planner, $arguments);

        return array_key_exists($question, $this->plans) ? $this->plans[$question] : $plan();
    }

    /** @param list<int|null> $arguments */
    private function question(string $planner, array $arguments): string
    {
        return $planner . '(' . implode(',', array_map(static fn (int|null $argument): string => var_export($argument, true), $arguments)) . ')';
    }
}
