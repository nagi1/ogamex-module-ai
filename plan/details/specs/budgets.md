# Operating budgets

Owner: runtime/cost. Every number below is a proposed starting limit or arithmetic example, not measured capacity. Provider prices are inputs, not frozen recommendations.

## Reference deployment profile

Stated by the owner on 14 September 2026, and now a decision criterion rather than a note:

> **2 vCPU, 2 GB RAM, no GPU** — an ordinary small VPS that already runs the Laravel app, its
> queue workers, the database and Redis.

Owner direction 16 September 2026: build the strongest account first and optimise later. This profile
is the eventual deployment target, not a gate — capabilities run at full strength now and are trimmed
against this machine once the strongest account is measured.

Measured in this workspace on 14 September 2026 (the development host, **not** the target
profile — recorded as an order of magnitude, not as target capacity):

| Service | Resident memory |
| --- | ---:|
| CBRKit sidecar | 142.1 MiB |
| FAtiMA sidecar | 108.9 MiB |
| Reverb | 59.4 MiB |
| Laravel app container | 55.8 MiB |
| Scheduler container | 3.1 MiB |

Both sidecars are **shared services, not one process per player**, so the constraint is resident
memory rather than per-account cost. After the application stack and the database, roughly
**500–700 MB** remains for anything new — an estimate to be measured, not a claim.

The per-candidate verdicts, the evidence behind them and the list of things that must never run
on this profile are in [how modern AI tooling solves agent memory](../research/agent-memory-tooling.md#verdicts-against-the-reference-profile).
Two rules follow directly:

1. **Native engines are the fallback and the floor, not the default.** FAtiMA at 109 MiB and CBRKit
   at 142 MiB are ordinary candidates; a Node runtime with a 920 MB dependency tree, a JVM graph
   database or a local language model are not.
2. **Every driver is enabled by default; the resident-footprint figure is recorded, not a gate.** The
   2/5/10-account runs measure the profile, and optimisation happens after the strongest account is
   built.

## Model-call policy

- Normal decisions, scheduling, recovery, game-event reducers, native memory, appraisal, authored social exchanges and structured CBR: **0 generative calls**. Their baseline also needs no embeddings.
- A known substantive social exchange first tries authored dialogue. Only selective human-language realization or interpretation/reply may reserve a foreground LLM request.
- Every enabled LLM request goes through the module's Laravel AI SDK `LanguageGateway` adapter; its provider/model choice and any SDK failover attempt are accounted as provider attempts in this ledger.
- Generate text and any fact/intent/commitment proposals together. No separate classifier, extractor, realization, judge or repair-model chain for the same reply.
- No autonomous AI-to-AI LLM loops, periodic reflection, automatic per-event summaries, background model-based personality changes or general gameplay batch planning.
- Rare strategic advice is a later opt-in Phase 3+ experiment, disabled in the baseline. The [activation gate](phase-3-cognition.md#later-capabilities-and-explicit-activation-gates) requires a meaningful unresolved major-event decision, cooldown, dedupe and a separately allocated share of the global budget. Normal policy never waits for it.
- External memory must not introduce hidden generative requests. AgentOS-style LLM detection/derive, auto-ingest, observation compression/reflection and HyDE remain disabled. Driver conformance tests must verify this configuration rather than rely on a vendor's zero-token claim.

## Starting conversation limits

The imported discussion targets roughly 1,000–2,000 input tokens through deterministic selection. These proposed profiles replace the earlier single 1,200/120 limit so an interpreted reply can include a bounded proposal envelope. They are configuration starting points, not measured consumption or provider recommendations.

| Route | Maximum total input | Maximum billed output | Scope |
| --- | ---: | ---: | --- |
| LLM realization | 1,200 tokens | 160 tokens | A settled social intention expressed briefly. |
| LLM interpretation/reply | 2,000 tokens | 320 tokens | One reply plus bounded interpretation and at most two fact/commitment candidates. |
| Strategic advice / deferred enrichment | Disabled; zero allocation | Disabled | Enabling requires a recorded purpose, caps and evaluation result; no default spend. |

Input includes system text, schema, wrappers, persona, cognition, facts, retrieved memories and the current turn. Output includes the entire structured envelope and any billed reasoning tokens, not just visible prose. Use a provider/model that can enforce or conservatively bound these totals. Unsupported usage accounting is a driver activation failure.

For a 2,000-token request, start with 300 instructions/schema, 150 persona, 150 cognition/intent, 250 current facts and exact commitments, 300 older relevant memory, 600 current/recent turns and 250 protocol/tokenizer margin. This is a selection budget, not padding to fill. Unused sections may lend space within the same total cap. Prioritize the current message, exact terms, source attribution and safety/legality constraints; if those alone do not fit, use an authored clarification or defer instead of truncating meaning.

Default pilot universe ceiling: **500 provider attempts/day**, including retries and any future advice/enrichment allocation, independently of population. Also configure per-account and per-human-conversation request, token and cost caps before enabling the provider; no unset/unlimited child budget. Human-requested replies have priority. Enrichment/advice has a separate sub-budget inside the global ceiling, not an escape from it.

Reserve the route's maximum input/output cost and one attempt atomically before dispatch. Count unsuccessful and uncertain attempts as well as successes. Reconcile actual usage once by provider request ID; retain the reservation on uncertain billing until reconciled. Changing provider or crossing a date boundary must not reset an outstanding reservation. Budget exhaustion selects queued-within-TTL, authored text or silence; it cannot trigger an unbudgeted fallback.

Record route/reason, provider/model/version, input/output/cached/embedding usage, reservation and actual cost, timing, retry count and final disposition. Avoid logging private prompt text by default. Cache stable prefixes where supported, but count uncached cost in the baseline. Cache keys must include persona/policy/scope versions; never reuse another conversation's private reply.

## Coalescing, batches and deferred work

| Work | Phase 3 baseline | Limits and meaning |
| --- | --- | --- |
| Coalesce pending human turns | Enabled | One conversation/owner/channel only; bounded idle window, turn count, maximum age and context. One foreground request for the sealed group. |
| Batch database reductions and memory projections | Enabled | Bounded rows/time, per-source idempotency and current ACL. No generative model required. |
| Finalize an episode after conversation idle/end | Enabled for deterministic records | Reuse already validated reply proposals and known events. Idle time does not authorize a second extraction call. |
| Provider Batch API for selected summaries/learning | Deferred, disabled | A future explicit experiment may process selected meaningful episodes or case clusters. Separate purpose/budget, expiry, per-item IDs, cancellation, partial-result handling and usage reconciliation are mandatory. |
| Batch routine ticks/gameplay decisions across players | Excluded | The deterministic game loop is not submitted to an LLM. |

Provider batches are asynchronous independent requests, not a prompt containing many players' private state. Delayed results must be checked against current scope, deletions, ruleset and commitments before acceptance. Batch discounts, latency and supported operations require verification for the chosen provider; do not build cost estimates on an assumed discount. No promises or time-sensitive replies may wait for a deferred batch.

Policy induction from many CBR cases was explored in the conversation, then deferred when Phase 3 was narrowed. Preserve it as a research question: evidence-backed proposals would need offline replay and human-reviewed policy promotion, with no executable generated code. It is not a periodic job authorized by this plan.

## Transparent cost model

For each token category, multiply monthly usage in millions by the corresponding provider price per million. Sum uncached input, cached input, billed output and embeddings.

Add memory-service charges, graph/vector hosting, SQL storage, workers, retries and observability. Include extraction, reranking and summarization in the appropriate token totals. Self-hosted models replace an API bill with compute/operations; they do not become free.

Example upper bound: 10,000 accounts × 2% receiving generated replies/day × 2 replies = 400 calls/day. If every call used the larger 2,000-input/320-output profile, that is 800,000 input and 128,000 output tokens/day, or 24M input and 3.84M output over 30 days. Multiply those monthly totals by the corresponding price per million. Authored replies and smaller realization requests reduce actual usage; measure their distribution instead of assuming a savings percentage.

Stress case: 20% × 2 = 4,000 desired replies/day. The 500-attempt global cap admits at most 500 requests, including retries; remaining work gets queued within TTL, authored text or silence. At the larger profile throughout, that conversation allocation is bounded by 30M input and 4.8M output tokens over 30 days. A future profile/cap change must update this arithmetic and reserve from the same global budget.

## Token-free does not mean computation-free

Initial per-session ceilings: 20 candidates, 12 galaxy observations, 6 probe decisions, 3 combat candidates and 3 fleet variants/candidate. Begin combat estimates in batches of 8 runs; cap at 64 runs per candidate and 192 per session. Apply an independent simulator time/memory limit calibrated on actual fleet sizes; run count alone is inadequate.

Target fewer than 50 ms p95 CPU for a ordinary policy evaluation excluding core I/O and queued battle simulation. This is a profiling target, not permission to skip correctness. Scheduler SLOs must be tested against the universe's minimum meaningful response window.

## Review overhead

The [review loop](improvement-loop.md) adds no work to a session: it reads on demand, off the request path,
and its only write is AG2's hourly points sample, a scheduled batch pass that takes no lock and calls no
service which would advance resources or stamp activity. That pass reports its query count and duration,
the "no impact on play" claim is supported by a session-cost comparison with `ai.review.enabled` on and
off, and the collection is best-effort so a lost sample costs a data point rather than a session. As with
every number on this page, the cost is measured before it is claimed.

Give cognition, CBR and memory retrieval their own measured request deadlines, candidate limits and concurrency ceilings. A thousand registered AI accounts does not mean a thousand active sidecar sessions or continuously running cognitive loops. Profile cold and warm calls, memory per active character, serialization, database I/O and failure backlogs on the [reference deployment profile](#reference-deployment-profile) before making capacity claims.

For optional PsychSim, start at self plus 1–3 relevant counterparts and depth 1; depth 2 requires measured benefit and capacity. Embeddings come from a hosted provider, never a local runtime: the reference profile has no headroom to serve one, so an embedder costs provider tokens and a network round trip per item rather than resident memory. Run them as bounded asynchronous projection work, not per-tick work, and compare input-token savings against provider cost, write-path latency and failure rate; a hosted call is cheap per item and unbounded in aggregate.

Illustrative workload: 10,000 accounts × 8 sessions × 6 decision jobs = 480,000 jobs/day, or 5.56 jobs/s average. Test at least 10× burst load plus fleet returns/events. If measured mean CPU is 20 ms, policy compute alone averages about 0.11 cores; this excludes database, core progression, simulation and peak demand and is not a server-sizing claim.

Bound hot memory, archive/aggregate old events and partition by universe/owner where the selected database supports it. For example, 10,000 owners × 200 retained items = 2M rows before messages, indexes and replicas. Measure bytes, not just row count. Pressure and probe spam must not create unbounded history.
