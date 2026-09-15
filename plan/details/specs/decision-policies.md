# Deterministic decision policies

Owner: gameplay. All algorithms below are design proposals. Use [core quotes](architecture.md) for mechanics and [player traits](player-model.md) for priorities.

## Common selection

1. Observe only when the routine allows it; reconcile own state and obligations.
2. Handle noticed emergencies and expiring commitments before discretionary growth.
3. Generate a bounded set of feasible intents, including wait. Core quotes provide costs and durations.
4. Normalize features into comparable ranges and score benefit, risk, goal progress, attention and commitment cost.
5. Keep the current goal unless an alternative exceeds a configurable switch margin. Among near-equal candidates use a seeded weighted choice.
6. Revalidate at execution. Record the chosen features, rejected reasons and next meaningful observation time.

Score goal progress and expected benefit positively; subtract exposure, attention cost and disruption of the current plan. Normalize against the actor's daily income, account value and session budget. A fixed million-resource gain cannot have the same meaning for a beginner and veteran.

Hard constraints precede scores. High utility cannot override missing fuel, a full fleet slot, forbidden attack or an impossible return time. Weight tuning must be explainable with scenario outcomes.

## Economy, research and logistics

Estimate marginal production over the actor's planning horizon using core production/cost quotes. Convert resources using configured valuation weights and current bottlenecks; these are planning preferences, not rules or guaranteed trade rates.

Compare usable incremental production with the opportunity cost of construction, energy and fuel.

Include construction delay, expected storage overflow during absence and energy-limited output. Compare energy options by effective production gained, cost and exposure; solar satellites should not become a universal answer. Preserve a reserve for the next feasible fleetsave and committed transport before discretionary spending.

Use a small prerequisite goal graph for research/colonization. Research with no direct income can score through an unlocked capability. Allocate scarce crystal/deuterium across the next bottleneck instead of rigid mine-level ratios. Reevaluate when a queue completes, a resource threshold is reached or the next session starts—not every second.

For colonies, rank a bounded set of legally observed slots by expansion goal, travel cost, expected specialization and geographic risk. Read supported temperature/field/slot behavior from the ruleset; do not copy modern OGame bonuses into a pre-Lifeforms fork. Coordinate cargo collection so resources and slots remain available when needed.

## Fleetsave and defense

Before leaving a session, search supported mission, destination and speed combinations for a return within the next intended availability window, with a margin for lateness. Use the core's outbound/return/recall semantics, cargo capacity, fuel and mission-slot rules.

Rank feasible routes by exposure, fuel, cargo protection and schedule fit. Deployment/recall and moon routes require verified mission and phalanx behavior; none is universally safe. If no good route exists, prioritize protection of valuable movable assets, reduce exposure through legal spending/transport, and record the remaining risk. Do not conjure a moon, destination or fuel.

Incoming-threat responses depend on observed time remaining and actual available actions. Select save, recall, reinforce, accept a small loss or stay. A defense fleet cannot use the server's secret knowledge of attacker composition. Ninja/ACS timing belongs to an advanced capability fixture before activation.

## Espionage and target choice

Maintain a small local candidate index from legal galaxy views, own reports and received intel. Sample discovery regions according to colonies, travel range and attention. No free universe-wide target database.

Expire confidence by information type: resources/fleets change quickly; coordinates or past losses change slowly. Unknown defenses trigger a probe or conservative rejection, not zero-defense assumptions. Request new intel when its expected decision value exceeds probe/time cost; stop scouting when the session budget expires.

## Raiding and simulation

Filter by legality, reach, cargo, fuel, recent pressure and report freshness. Generate a few role-based fleet compositions from available ships; avoid enumerating all subsets.

Estimate loot and recoverable debris, then subtract ship losses, fuel, recycling cost and the cost of exposure and occupied fleet slots. Account for cargo limits, recycler capacity, travel delays, competing debris collection and chance the target changes before impact. Debris is never guaranteed income.

Simulate alternative plausible defender states when intel is incomplete. Randomized battle runs estimate combat variance only; they do not remove intelligence uncertainty. Never reuse the actual future combat random seed.

Use batches of samples, stop clearly bad options early, and continue close decisions only within [budgets](budgets.md). Favor a conservative profit quantile appropriate to the profile. If estimates remain unstable, probe/wait/pass. Cache by visible-intel fingerprint, fleet, technology assumptions, ruleset/engine version and estimator configuration; invalidate on changes. Cached statistics reveal no other actor's unseen observations.

## Expeditions, diplomacy and recovery

Expeditions use ruleset-specific templates and empirical own-report outcomes, with fleet-loss and slot opportunity costs. Disable if capability is absent. Never use actual hidden expedition outcomes to pick missions.

A finite-state diplomacy policy proposes, accepts, declines or expires specific agreements based on trust, resources and availability. Language can phrase those decisions; it cannot invent binding terms. Alliance cooperation uses delayed authorized reports and independently accepted requests.

Loss recovery follows the [player state model](player-model.md). Biases and mistakes arise from attention, old evidence, preferences and incomplete preparation—not random suicide launches.

## Strategy mining and the wave-6 enrichment

The [strategy mining](strategy-mining.md) programme and the [principle catalog](../research/strategy-principles.md)
map these policies onto validated OGame play. Its claim layer — which distinguishes domain facts,
hard-safety policies, scoring factors, heuristics, profile parameters and advanced tactics — is
[`research/strategy-claims.md`](../research/strategy-claims.md). The wave-6 strategy-depth gaps
(activity risk in raid, spy target ranking, proactive fleetsave, fleetcrash/phalanx/moon) are depth,
not capability: the host supports every seam, the module does not yet reach it. The algorithm blocks
for them — T6–T8, N4/N5, V6–V8, F1–F6 — are written in
[gameplay-algorithms.md](gameplay-algorithms.md) and stay **blocked on catalog review**; no policy
here is amended by them until the integration gates in the mining spec are met.
