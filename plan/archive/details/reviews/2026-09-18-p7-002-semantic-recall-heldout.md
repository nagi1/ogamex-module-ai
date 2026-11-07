# Review record — P7-002 semantic recall held-out review (18 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "p7-002-semantic-recall-heldout",
  "date": "2026-09-18",
  "artifacts": {
    "benchmark": "scripts/e2e-agentos-recall-benchmark.php"
  },
  "counters": {
    "trials": 10,
    "facts_per_counterparty": 30,
    "recall_limit": 20,
    "required_fact_recall": { "native": 70.0, "external": 100.0, "hybrid": 70.0 },
    "newest_fact_kept": { "native": 10, "external": 1, "hybrid": 10 },
    "order_differs_from_native": { "external": 10, "hybrid": 10 },
    "scope_leaks": 0,
    "latency_ms_p50": { "native": 2.2, "external": 13.4 },
    "decision_parity": {
      "production_amount": 10,
      "external_reachable": 10,
      "hybrid_reachable": 10,
      "external_with_query_text": 7
    },
    "provider_calls": 0
  },
  "findings": [
    "external mode lifts required-fact recall 70% -> 100% but drops newest-fact survival 10/10 -> 1/10 — substitution, not addition",
    "hybrid mode keeps newest-fact survival at 10/10 and required-fact recall at 70% (parity with native), and every decision is identical to native at both amounts",
    "native retrieval still wins: the held-out review does not show a miss that semantic recall closes",
    "semantic recall stays disabled"
  ]
}
```

## What happened

The Package 7B gate is a held-out review that shows native retrieval misses what exact/entity recall
closes. The module's own real-stack benchmark (`scripts/e2e-agentos-recall-benchmark.php`) was run
against the live AgentOS sidecar (10 trials, 30 facts per counterparty, 20-fact cut): external mode
trades the newest fact for the debt fact (substitution), and hybrid mode is decision-identical to
native. The evidence points away from enabling, so 7B stays disabled and the row is closed.
