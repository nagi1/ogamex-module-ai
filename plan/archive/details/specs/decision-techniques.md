# Which decision technique to use, and which not to build

Research note, 14 September 2026. Answers the question "how do we make the accounts plan like experts with
the least possible dependence on language models" by comparing the candidate techniques and, more
usefully, by naming the ones that would be machinery rather than decisions. The conclusion is that the
module already has the right spine and needs two additions, not a framework.

## The ranking

| Technique | Fits which decision | Simplicity | Authenticity | Dependency cost | Verdict |
| --- | --- | --- | --- | --- | --- |
| Utility scoring / weighted ranking | Which building, research or unit next; which session action | 5 | 4 | none | **The spine.** The module already has `UtilityScorer` and `CandidateActionFactory` |
| Marginal payback ordering | Build order, save-versus-spend | 5 | 5 | none | **Adopt.** The continuous-knapsack optimum, and what veterans describe |
| First-match rule chain / decision table | Energy interlock, safety gates, fleetsave trigger | 5 | 4 | none | **Adopt** for hard interlocks only, and keep the tables small |
| Hysteresis and cooldowns | Damping oscillation, one ambition at a time | 5 | 5 | none | **Adopt.** Output depends on history, which is how people avoid flip-flopping |
| Queue and time-to-afford scheduling | How full to keep queues, when to wait | 5 | 5 | none | **Adopt.** Costs grow geometrically, so reasoning in cost-over-rate keeps the tier cadence human |
| Bounded case-based adjustment | A small nudge from remembered outcomes | 4 | 4 | none | **Keep as it is**, and stop where retrieval would need indices or adaptation |
| Routine planning with distributions | Sessions across the day, absence | 4 | 5 | none | **Adopt.** The distribution *is* the persona |
| Heavy-tailed gap and session distributions | Routine planning, reaction latency | 5 | 5 | none | **Adopt.** Measured: human inter-event times are heavy-tailed, not Poisson, and a uniform gap has no tail |
| Common random numbers (paired seeds) | Comparing candidates through a stochastic estimator | 5 | 4 | none | **Adopt** where a sampler is used: one seed stream per candidate set, so a difference is the candidate's |
| Lower-tail statistic (quantile or loss count) | The raid go/no-go | 5 | 5 | none | **Adopt.** A mean-profit reading is documented to have inverted a real decision by 60 M resources |
| Adaptive capped threshold | The payback horizon a persona will accept | 5 | 4 | none | **Adopt.** `min(cap, base × (1 + progress))` — one line, and it ages with the account |
| ε-greedy or UCB1 bandit | Choosing between 2–3 plausible orders | 5 | 3 | none | **Only with a measured need**, and persona-shaped rather than regret-optimal |
| Thompson sampling | The same, with Bayesian uncertainty | 3 | 3 | none | **Defer** until UCB1 is insufficient |
| HTN planning | Decomposing a session into goals | 3 | 4 | none | **Restrict** to a tiny table of methods; general HTN is undecidable |
| Fuzzy control | Smooth urgency curves | 3 | 3 | none | **Reject.** Utility response curves already do this |
| GOAP or PDDL planning | Multi-step plans | 2 | 3 | none | **Reject.** Shipped game plans are one or two actions deep and replanning costs real CPU |
| Monte Carlo tree search | Choosing under uncertainty | 2 | 2 | a simulator | **Reject.** Needs thousands of rollouts per decision |
| MDP value or policy iteration | Save-versus-spend as a policy | 2 | 2 | none | **Reject** for routine decisions; the state space explodes |
| Rule engine (RETE) | Large rule bases | 1 | 3 | a rule engine | **Reject.** We have a handful of rules; a table is enough |
| ILP or MILP solver | Optimal purchase set | 1 | 3 | an external solver | **Reject.** The linear relaxation *is* the greedy ratio sort |

Sources for the techniques: [utility systems](https://en.wikipedia.org/wiki/Utility_system),
[payback period](https://en.wikipedia.org/wiki/Payback_period),
[continuous knapsack](https://en.wikipedia.org/wiki/Continuous_knapsack_problem),
[decision tables](https://en.wikipedia.org/wiki/Decision_table), [hysteresis](https://simple.wikipedia.org/wiki/Hysteresis),
[incremental games](https://en.wikipedia.org/wiki/Incremental_game), [case-based reasoning](https://en.wikipedia.org/wiki/Case-based_reasoning),
[HTN](https://en.wikipedia.org/wiki/Hierarchical_task_network), [MCTS](https://www.geeksforgeeks.org/ml-monte-carlo-tree-search-mcts/),
[automated planning](https://en.wikipedia.org/wiki/Automated_planning_and_scheduling),
[F.E.A.R. GOAP post-mortem](https://www.gamedeveloper.com/design/building-the-ai-of-f-e-a-r-with-goal-oriented-action-planning),
[Rete algorithm](https://en.wikipedia.org/wiki/Rete_algorithm).
Sources for the techniques added by the second pass: [common random numbers](https://en.wikipedia.org/wiki/Common_random_numbers),
[lower-tail and quantile reporting](https://en.wikipedia.org/wiki/Quantile), [Weibull analysis of dwell time](https://www.microsoft.com/en-us/research/wp-content/uploads/2010/10/dwelltime.pdf),
[heavy tails in human dynamics](https://www.nature.com/articles/nature03459), [payback period](https://en.wikipedia.org/wiki/Payback_period).

## The two additions the module actually needs

### 1. A payback score for the economy, replacing the arbitrary order

```text
for each object the host offers that the building queue accepts:
    cost  = host price of the next level, in the accepted trade weights
    gain  = host production at next level - host production at this level
    score = gain / cost
    if the current target's payback is inside the persona's switch margin:
        break the tie on host build time, and on whether the build finishes before the
        next planned spend or the next absence                    # queue occupancy, as a tie-break

take the best affordable; stop when the best payback exceeds the persona's horizon
```

Two details matter and are easy to miss: the host's production answer is scaled by the planet's current
energy factor, so raw output has to be asked for explicitly; and a build occupies the only queue slot the
planet has, which is why the tie-break above exists.

**Retraction (second, deeper source pass, 14 September 2026).** The earlier version of this note
recommended scoring `gain / (cost × hours)` whenever the queues were contended. That metric has **no
precedent** in the eleven surveyed projects: five of them read the queue only as a boolean occupancy
guard, and the one that computes a construction time never feeds it back into its ordering key. Adopting
it would be an optimisation nobody measured, which gate 2 refuses. It therefore appears only as the
tie-break above, and [`gameplay-algorithms.md`](gameplay-algorithms.md#e2-queue-occupancy-honest-about-what-is-not-proven)
states the measurement that would let it be promoted later.

### 2. A predictive energy interlock

```text
if planet.energy < 0: build the cheapest capacity the host offers
else: for each production object, if energy + gain(next level) < 0: build capacity first
```

Hysteresis comes free from this form: once capacity is added the interlock stops firing until the mines
catch up again, so the account does not oscillate between mine and plant.

## Do not build

- **A solver or planner subprocess** in any form. The linear relaxation of the build-order problem is the
  greedy ratio sort, and integer search is NP-hard and unnecessary.
- **A rule engine, inference engine or fuzzy controller.** A dozen rules do not need one, and each brings
  tuning cost with no measured benefit.
- **A simulator in the routine path.** Rollout-based planning needs a simulator and a CPU budget the
  reference profile does not have; the offline simulator that *does* belong in the project is for
  replaying recordings, not for choosing actions.
- **A learned model or training pipeline.** No GPU, no data, and no measured gap to close.
- **A bandit as the primary driver.** A veteran does not minimise regret; using a bandit as the decision
  maker trades authenticity for an optimality the goal does not ask for.
- **A second memory authority beside the case base.** One bounded score term, not a parallel system.

## What the second, deeper source pass added and removed

The pass that read the real source of eleven automation projects, the OGameX host and the bot-detection
literature changed this note in four places. Each is a technique, not a preference, and the evidence is in
[the automation survey](../research/ogame-automation-algorithms.md).

**Added.** *Common random numbers*: pair every candidate on one seed stream so a fitness difference is the
candidate's, not the sampler's — the only methodology worth taking from a tool that otherwise spends its
budget on a genetic search. *A lower-tail statistic*: order by a lower quantile and count the losing runs,
instead of reporting a mean, because the one documented failure in the corpus is a mean that inverted a
60 M decision. *Heavy-tailed gaps*: draw session lengths and inter-action gaps from a log-normal or a
Weibull with shape `k ≈ 0.7–0.9` rather than a uniform window, because uniform support is exactly what a
periodicity detector looks for. *An adaptive capped threshold*: `min(168 h, 24 h × (1 + average mine
level / 20))`, so the account's patience ages with it.

**Removed.** The `gain / (cost × hours)` ordering metric (no precedent; now a tie-break). A single
mean-profit output (documented to invert decisions). A hard `win probability ≥ 0.95` gate (not measurable
at the sample sizes that would use it). An unbounded simulation count. An expected-value battle engine
(`opbe`'s O(1) pass is a modelling choice, validated by its own author against thousands of runs).

**Confirmed.** The rest of the ranking survives the deeper pass unchanged: utility scoring over
host-quoted numbers, marginal payback, small threshold tables, hysteresis, queue-and-time-to-afford
scheduling, a bounded case-base nudge, and routine distributions. Nothing heavy was added, and nothing
heavy that the first pass rejected has become necessary — the whole strategic loop remains arithmetic, one
sort key, a few thresholds and a wake-time computation.

## How this stays cheap

Every recommendable technique above is arithmetic over host-quoted numbers plus a small table, executed
once per decision (minutes apart, per account). Nothing needs a service, a cache, a worker of its own or
a model call, which is what keeps the ordinary path at zero generative cost and inside the
2 vCPU / 2 GB [reference profile](../specs/budgets.md).

The only places a model may appear stay where the plan already puts them: an unrestricted human sentence
that has to be understood, and nothing on the decision path.
