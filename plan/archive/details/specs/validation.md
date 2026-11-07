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

## Test harness invariants

These are properties of the deterministic harness, not of any single test. Each was
found by diagnosing a real stall, and each is enforced by `scripts/ogamex` so it cannot
quietly return. A violation shows up as a suite that never finishes, not as a failure.

| Invariant | Why it matters |
| --- | --- |
| A wait that must expire is bounded by `microtime(true)`, never by `Carbon::now()`. | Laravel's `Cache\Lock::block()` derives its deadline from `Carbon::now()`. Feature tests freeze the clock with `IsolatedAccountTestCase::travelTo()`, so that deadline never arrives and the retry loop spins forever. `FatimaCognitionSession::acquireWithin()` is the reference implementation. |
| Every run is bounded by `timeout` **inside** the container. | `docker compose exec` does not forward a signal, so a killed or timed-out client leaves the worker alive inside the container. A survivor keeps its transaction and row locks, and with the host's `innodb_lock_wait_timeout = 1` every later run then fails in one second — a stale lock that reads as a hang. `TEST_TIMEOUT` and `COVERAGE_TIMEOUT` own that bound; reaching it means a hang, not slowness. |
| Stale sessions are reaped before a run. | `scripts/ogamex reap` releases sessions left idle inside an open transaction, and runs before `test`, `test-all`, `quality` and `coverage`, so one interrupted run cannot poison the next. |
| Leftover workers are cleared by database session, not by process listing. | The application container ships neither `ps` nor `pkill`. |

A full module suite is roughly 300 tests and completes in about thirteen seconds with four
workers; a warm run is under two. Anything slower is a defect in a test, so never
recover a slow run by raising a timeout or adding workers — eight workers measured
*slower* than four on a ten-core host.

## Phase 3 behavior and failure matrix

These are implementation acceptance scenarios for [Phase 3](phase-3-cognition.md), not assertions that the tests exist today. Write native Pest 5 tests and named datasets using real OGameX models, committed records, services, queues and database transactions where practical. Use PAO and PCOV; never Xdebug, PHPUnit-style module tests or Mockery. A narrow container replacement is justified for driver conformance/outage experiments and must be tested alongside the actual enabled adapter.

| Scenario / dataset | Required observation |
| --- | --- |
| Greeting, thanks, routine acknowledgement | Authored route, zero generative requests, correct recipient and one delivery receipt. |
| Known substantive resource/trade request | Social policy handles accept/reject/counter/clarify without an LLM; exact terms survive persistence. |
| Unsupported trade/transport capability | No false delivery promise, fleet launch or fulfilled case; explicit decline/clarification/unsupported result. |
| Prior betrayal → apology → two fulfilled trades | Current trust/stance can improve while betrayal history and outstanding obligations remain. Verify the selected response's supporting reasons. |
| Same loss, different persona | Miner/Turtle/Fleeter/Trader/Casual appraisal/social consequences differ according to authored policy while facts and legal knowledge remain identical. |
| Low/high loyalty, patience, honesty or vengefulness within a persona | Named trait settings produce the documented interaction differences; no hidden resource/skill advantage. Do not invent traits without defining their native mapping. |
| Anger decays while debt remains | Frozen-time decay changes transient affect; exact outstanding obligation stays enforceable until a valid transition. |
| Promise for 2M crystal, only 1.7M available when due | No overspend or invented fulfillment; current policy chooses a permitted partial/renegotiation/refusal path and records its exact terms. |
| Complex conditional negotiation | One generation returns interpretation/text/proposals; deterministic validation rejects ambiguous deadlines, unknown parties, invalid units or unaffordable/unsupported commitments. |
| Model text commits to terms that policy rejects | No contradictory text is sent. Authored clarification/refusal or silence, with no second generation to repair it. |
| “Raven says Draco will attack” | Preserve speaker, source and uncertainty. No verified attack intent or automatic punishment when the attack does not occur. |
| Alliance claim → reported departure → verified new membership | Source time and observed time stay distinct; latest verified fact wins for current state, with attributed historical claims retained. |
| Two observers learn the same event differently | Common source fact, different allowed knowledge/salience; no shared private history or retroactive awareness. |
| AI-to-AI request/counteroffer loop | Typed bounded protocol, no LLM even for human-visible rendering, no duplicate consequences or unbounded exchanges. |
| CBR cold start / no similar cases | Ordinary policy continues; no automatic strategic LLM call. |
| CBR successful, failed and incomplete outcomes | Only real correlated final outcomes affect learned evidence; record-only intent is not a completed action. |
| CBR missing features, tied scores, outliers, contradictory cases | Defined normalized similarity and stable tie handling; unknown values remain unknown and evidence confidence remains bounded. |
| CBR old ruleset/feature schema, wrong owner or private seed cases | Excluded or explicitly migrated/authorized; no cross-owner knowledge leak or incompatible vector/feature comparison. |
| Novice/veteran and each archetype with controlled experience | Different experience access influences allowed choices as specified; no omniscient shared training history or bypass of policy constraints. |
| Duplicate/rolled-back/late event | One accepted observation/state consequence; rollback produces none; late events cannot overwrite newer truth. |
| Same event affects affect/social/CBR | Shared provenance with distinct purposes; integrated FAtiMA session advances once and projections cannot form a self-reinforcing fact loop. |
| Coalesced messages; new turn arrives during generation | One sealed request per generation; new pending work retains its source identity, and stale in-flight text is checked before sending. |
| Continuous sender / oversized message / repeated variants | Maximum coalescing age and context are enforced; protected terms/current message are not silently truncated; English cases remain attributed. |
| Recipient blocks AI or leaves alliance during generation | Delivery is refused under current host-equivalent policy; no new generation or disclosure to the old channel. |
| Deleted source/reply-to target or module disabled mid-flight | No stale delivery or fact resurrection; appropriate expiry/cancellation and usage accounting. |
| Two sequential actors in a long-lived worker | No persona, ACL, channel, cache, driver-session or credential context leaks. |
| Concurrent reservation at final daily allowance | At most the allowed provider attempts; retries/failures count; advice/enrichment cannot evade parent caps. |
| Timeout with unknown billing / crash before usage settlement | Outstanding reservation retained and reconciled once; no blind duplicate generation. |
| Crash after chat persistence / before delivery completion | Existing chat ID is reconciled; no duplicate persisted message. Broadcast behavior is tested separately from durable writes. |
| Wrong schema, truncated JSON, prompt injection, unknown fact IDs | Reject proposals and unsafe text; no tools, hidden facts, raw trust writes or executable actions. |
| Native record retention/access expires or is deleted while optional index lags | All retrieval paths, including direct ID and cached/provided IDs, hide it immediately; deletion retries cannot resurrect it. Temporal validity expiry alone preserves authorized historical evidence. |
| FAtiMA/CBRKit/AgentOS/embedding/LLM unavailable | Native or disabled fallback from the failure matrix; no blocked gameplay worker or mandatory missing sidecar. |
| Driver swap or restart with active agreement | Persona, exact obligations, sources and accepted cognitive state remain; incompatible checkpoints are explicitly rebuilt/versioned. |
| Context selection, optional compression | Hard total and per-section limits; provenance, claim/commitment terms, current input and legal constraints remain intact. |
| External memory profile advertised as zero-token | Instrument the real configured driver: no generative requests; embeddings/network/CPU are separately accounted for. |

Map tests to the eight end-to-end flows and milestones. Use pairwise datasets for independent combinations, plus explicit multi-factor scenarios for important interactions such as stale membership + in-flight reply + provider timeout. Do not mechanically multiply every enum or assert only the implementation's own score formula. Expected outcomes must demonstrate player behavior, host effects or preserved invariants.

Keep complete branch/edge-case scenarios alongside the 100% changed-area PCOV line-coverage gate. PCOV does not report branch coverage; a line percentage cannot prove every behavior. Pure domain tests cover similarity/decay/validation boundaries, and feature tests prove the corresponding mechanics through the real module application. Run the existing Phase 2 regressions and the required Rector/Pint/PHPStan/Pest/PAO/PCOV/TIA checks after implementation, not as a claimed result of this documentation revision.

## Driver experiments and model-quality evaluation

Separate reproducible CI from opt-in external evaluations. CI uses the production native/disabled implementations and a narrow controlled provider boundary for error/schema/budget cases. For 3H, use Laravel AI's structured-agent fake with stray prompts prevented, alongside the module `LanguageGateway` boundary; this verifies the SDK invocation without a network call. Any enabled external driver also needs a real integration/conformance run against a pinned runtime/provider; passing only a replacement stub does not certify the adapter. Live LLM quality/cost evaluations are explicit budgeted runs on sanitized/consented fixtures, not uncontrolled calls on every CI execution.

Use the precise [A–G configurations](../research/memory-comparison.md#evaluation-corpus-and-experiments): A disables affect/experience enrichment while retaining native facts/social rules; B enables affect; C adds experience; D/E/F independently extend C with recall, Theory of Mind or semantics; G changes only the language provider against a fixed cognition configuration. Test native/external replacements separately from enabling/removing a capability. Use the same source fixtures, ruleset, persona and clock/seed where applicable; record external nondeterminism and repeat trials. Test English messages only, per the recorded language decision. Measure route distribution, believability, repetition, factual/proposal correctness and baseline differences; do not use exact generated wording as a deterministic assertion.

For 2/5/10 registered players, specify active fraction, message/event rate, history size and burst profile. Report hardware, concurrency, evaluations/second, average/p95 prompt size, all token categories, cost per active human conversation, CPU/RAM and queue lag. These are experiments to discover capacity; no throughput or cost-saving percentage is assumed. [Driver evaluation](../research/memory-comparison.md) defines the optional-memory threshold and reporting template.

## Release gates

Host/module integration adds explicit checks for rollback-suppressed notifications, battle simulation without live opponent reads or committed events, controller/service validation parity and fresh actor context in long-lived workers. Test the existing module's enabled/disabled resource registration using isolated status files. [Extension work](module-extension-points.md) owns the exact gates.

Before a human pilot: all correctness fixtures pass; zero observed information leaks, duplicate dispatches or resource corruption; 30 simulated days without silent scheduler starvation; measured budget compliance under normal and adversarial message/probe traffic.

For believability, ask consenting OGame reviewers to rate disclosed AI traces for sensible goals, routine consistency, mistakes and recoverability. Proposed gate: median at least 4/5 for both plausibility and fairness, with no repeated critical failure. This is not a test of whether reviewers can be deceived.

## Measure humans separately

Track D7/D30 return rates by new/returning human cohort, meaningful interactions per human-week, repeated reciprocal contacts, proportion continuing after a loss and short enjoyment/fairness feedback. Track reports, pressure concentration, opt-outs and stated unwanted check-in burden as guardrails. Exclude AI accounts from human retention denominators.

Define a meaningful interaction as an actual raid/defense decision, fulfilled trade, accepted assistance or substantive two-way conversation—not a probe or generated greeting. Count opportunities and outcomes, not just raw volume.

Run a disclosed pilot for at least four weeks after Phase 3, starting with a small population. Compare with a pre-pilot baseline or matched universe with similar speed/age/activity. Prefer universe-level treatment because players interact; regional cohorts have spillover. Tiny dead-server samples cannot establish causal retention uplift. Report uncertainty and qualitative feedback; do not optimize to a statistically noisy single percentage.

## Capacity and rollback

For [Package 6](../../WORK-PACKAGES.md), separately test coalition restrictions across all hostile paths, provider disappearance, in-flight shutdown, objective/report deduplication, contribution abuse and private report visibility. Pilot both a successful campaign and a failed attempt followed by recovery. Count human coalition participation separately from enemy activity and normal-universe retention.

Measure 50 → 500 → 5,000 → 10,000-account synthetic loads with realistic active fractions, crowded neighborhoods, synchronized returns, provider failures and combat sizes. Record hardware, p50/p95/p99 lag, CPU, database work, storage growth and cost. Admission stops at the first violated SLO even if the account target is unmet.

Suggested pilot stop trigger: any correctness leak/duplication, or sustained queue lag beyond the ruleset's response SLO. Review pressure and complaint increases before adding accounts. Freeze new admissions and attacks when required, disable language independently, preserve existing core flights, and keep read-only decision traces for diagnosis. Operators can then resume at a smaller tested envelope.

## After the pilot: read the results

The pilot does not end with a report. Each window is read as a dated [review record](../reviews/), which
answers the goal-shaped questions — capability chain, explicability of growth, cohort divergence,
reaction to pressure and failing saves, human aliveness — from artifacts the module already writes, and
feeds each finding into the [gap register](../GAP-REGISTER.md). The latest record is evidence for the
sign-off and for any population increase, and it states what stayed unmeasured. Rules:
[the review loop](improvement-loop.md).
