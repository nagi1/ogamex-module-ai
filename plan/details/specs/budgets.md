# Operating budgets

Owner: runtime/cost. Every number below is a proposed starting limit or arithmetic example, not measured capacity. Provider prices are inputs, not frozen recommendations.

## Hard model limits

- Normal decision, game-event ingestion, recovery and scheduling: **0 model tokens**.
- Ordinary generated reply: up to 1,200 input and 120 output tokens. Include system instructions, schema and wrapper overhead in the input count.
- Starting input allocation: 200 instructions/persona, 150 current intent/state, 250 relevant memories, 500 recent turns, 100 protocol allowance. Trim deterministically to the actual tokenizer count.
- Default global ceiling: 500 generated replies/day for a pilot universe, independently of population size. Per-account and per-human-conversation caps prevent monopolization. Operators can change the ceiling after evaluation.
- No autonomous AI-to-AI LLM loops. No periodic LLM reflection, automatic summarization of every event, or strategic model calls.

Reserve maximum cost atomically before provider dispatch. Count attempts and all billed tokens, including cached input and any reasoning tokens. Reconcile actual usage; retain the reservation on uncertain billing until reconciled. Budget exhaustion selects template/silence, never a more expensive fallback.

Batch pending messages before generating. Retrieve only relevant memory. Cache static persona/policy prefixes where the chosen provider supports it, but do not count cache savings in the baseline. Reuse authored phrases with variety and cooldowns; never reuse another player's private reply.

## Transparent cost model

For each token category, multiply monthly usage in millions by the corresponding provider price per million. Sum uncached input, cached input, billed output and embeddings.

Add memory-service charges, graph/vector hosting, SQL storage, workers, retries and observability. Include extraction, reranking and summarization in the appropriate token totals. Self-hosted models replace an API bill with compute/operations; they do not become free.

Example: 10,000 accounts × 2% receiving generated replies/day × 2 replies = 400 calls/day. At 1,200 input + 120 output each, that is 480,000 input and 48,000 output tokens/day; over 30 days, 14.4M and 1.44M. Monthly generation cost is 14.4 times the input price per million plus 1.44 times the output price per million before extras.

Stress case: 20% × 2 = 4,000 desired replies/day. The 500-call global cap admits at most 500; the remainder get queued within TTL, templates or silence. The cap limits that reply workload to 18M input and 1.8M output tokens/month. Optional enrichment must have a separate reservation pool so it cannot evade the cap.

## Token-free does not mean computation-free

Initial per-session ceilings: 20 candidates, 12 galaxy observations, 6 probe decisions, 3 combat candidates and 3 fleet variants/candidate. Begin combat estimates in batches of 8 runs; cap at 64 runs per candidate and 192 per session. Apply an independent simulator time/memory limit calibrated on actual fleet sizes; run count alone is inadequate.

Target fewer than 50 ms p95 CPU for a ordinary policy evaluation excluding core I/O and queued battle simulation. This is a profiling target, not permission to skip correctness. Scheduler SLOs must be tested against the universe's minimum meaningful response window.

Illustrative workload: 10,000 accounts × 8 sessions × 6 decision jobs = 480,000 jobs/day, or 5.56 jobs/s average. Test at least 10× burst load plus fleet returns/events. If measured mean CPU is 20 ms, policy compute alone averages about 0.11 cores; this excludes database, core progression, simulation and peak demand and is not a server-sizing claim.

Bound hot memory, archive/aggregate old events and partition by universe/owner where the selected database supports it. For example, 10,000 owners × 200 retained items = 2M rows before messages, indexes and replicas. Measure bytes, not just row count. Pressure and probe spam must not create unbounded history.
