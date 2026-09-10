# Validation, pilot and rollout

Owner: QA/product/runtime. Thresholds are proposed release gates; baseline observations and hardware measurements do not exist yet.

## Behavioral and correctness suite

Use an injected clock, fixed seeds and versioned rulesets. Test allowed outcomes and invariants, not brittle preferences such as “solar plant must always rank first.”

| Fixture | Required result |
|---|---|
| Hidden fleet/resource change | With identical legal observations and seed, candidate scores/action/timing remain identical. |
| Incoming attack during sleep | Hidden event cannot wake the account; response starts only after a legal observation/notification. |
| Fleet return near session end | Save/return planning uses real mission semantics and preserves fuel/slots or records unavoidable risk. |
| Resource spent after quote | Core rejects safely; no negative resources or duplicated retry. |
| Crash after fleet dispatch | Reconciliation finds the existing operation; no second launch. |
| Old spy report / uncertain defender | No false certainty or free hidden fleet lookup; probe/pass remains possible. |
| Major fleet loss | Persistent identity, changed goals and ordinary funded recovery. |
| New alliance / old promise | Current visibility and terms win over stale summary; unauthorized memory is absent. |
| Prompt injection / memory poisoning | No tools, secret facts, privilege escalation or unapproved commitments. |
| Provider outage / budget exhaustion | Zero model calls after cap; gameplay continues and replies degrade cleanly. |
| Offline backlog / duplicate event | No burst of retroactive attacks and no duplicate memory/action. |

Core rule tests remain in core; the module adds contract, behavior and end-to-end tests where its boundary can fail. Include paired human-action/AI-action equivalence for each new adapter.

## Release gates

Host/module integration adds explicit checks for rollback-suppressed notifications, battle simulation without live opponent reads or committed events, controller/service validation parity and fresh actor context in long-lived workers. Test the existing module's enabled/disabled resource registration using isolated status files. [Extension work](module-extension-points.md) owns the exact gates.

Before a human pilot: all correctness fixtures pass; zero observed information leaks, duplicate dispatches or resource corruption; 30 simulated days without silent scheduler starvation; measured budget compliance under normal and adversarial message/probe traffic.

For believability, ask consenting OGame reviewers to rate disclosed AI traces for sensible goals, routine consistency, mistakes and recoverability. Proposed gate: median at least 4/5 for both plausibility and fairness, with no repeated critical failure. This is not a test of whether reviewers can be deceived.

## Measure humans separately

Track D7/D30 return rates by new/returning human cohort, meaningful interactions per human-week, repeated reciprocal contacts, proportion continuing after a loss and short enjoyment/fairness feedback. Track reports, pressure concentration, opt-outs and stated unwanted check-in burden as guardrails. Exclude AI accounts from human retention denominators.

Define a meaningful interaction as an actual raid/defense decision, fulfilled trade, accepted assistance or substantive two-way conversation—not a probe or generated greeting. Count opportunities and outcomes, not just raw volume.

Run a disclosed pilot for at least four weeks after Phase 3, starting with a small population. Compare with a pre-pilot baseline or matched universe with similar speed/age/activity. Prefer universe-level treatment because players interact; regional cohorts have spillover. Tiny dead-server samples cannot establish causal retention uplift. Report uncertainty and qualitative feedback; do not optimize to a statistically noisy single percentage.

## Capacity and rollback

For [Package 5](../../WORK-PACKAGES.md), separately test coalition restrictions across all hostile paths, provider disappearance, in-flight shutdown, objective/report deduplication, contribution abuse and private report visibility. Pilot both a successful campaign and a failed attempt followed by recovery. Count human coalition participation separately from enemy activity and normal-universe retention.

Measure 50 → 500 → 5,000 → 10,000-account synthetic loads with realistic active fractions, crowded neighborhoods, synchronized returns, provider failures and combat sizes. Record hardware, p50/p95/p99 lag, CPU, database work, storage growth and cost. Admission stops at the first violated SLO even if the account target is unmet.

Suggested pilot stop trigger: any correctness leak/duplication, or sustained queue lag beyond the ruleset's response SLO. Review pressure and complaint increases before adding accounts. Freeze new admissions and attacks when required, disable language independently, preserve existing core flights, and keep read-only decision traces for diagnosis. Operators can then resume at a smaller tested envelope.
