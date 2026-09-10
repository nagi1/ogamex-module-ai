# Memory system comparison

Checked 10 September 2026. This is a workload-fit recommendation, not a measured benchmark. Vendor APIs and packaging change; pin versions in the implementation spike.

## Recommendation

**Implement native structured memory first. Benchmark Mem0 as the first optional conversational-memory adapter.** Evaluate Graphiti/Zep if temporal relationship retrieval demonstrably fails the native baseline. Letta is a broader runtime alternative, not a needed dependency for this design.

The majority of this game's memories are already structured facts. Sending each building completion or probe to a language model would spend tokens recovering structure the game already knows. External semantic memory remains valuable for long, ambiguous conversations; it need not own every account's gameplay state.

| Option | Fit and capabilities | Cost/operations implications | Proposed use |
|---|---|---|---|
| Native SQL events + facts + full-text | Explicit actor scope, evidence and validity; exact joins for known relationships | Zero model tokens for typed writes/reads; database/storage/engineering still cost money | Required baseline; reuse the existing database |
| Mem0 OSS | Self-hosted memory SDK; scoped add/search APIs | Operate runtime/storage; pay or provision inference and embedding compute | First optional bake-off adapter; pin actual OSS features |
| Mem0 Platform | Managed extraction/retrieval; current docs describe native entity graph support | Service fees/quotas plus any separately charged model work; inspect usage rather than assume it is included | Alternative to operating the OSS sidecar |
| Graphiti OSS | Temporal graph with provenance and hybrid retrieval | Python, graph backend, inference and embeddings; more infrastructure | Evaluate for complex changing relationships only |
| Zep | Managed context graphs and governance | Hosted/VPC commercial offering; quote total ingestion/retrieval/storage cost | Consider when managed operations beat self-hosting economics |
| Letta | Persistent agents and memory blocks; agent-managed context | Additional runtime and potentially ongoing memory-processing model work | Defer; gameplay already has an explicit scheduler/policy engine |

Mem0 documents LLM extraction with `infer=True`; `infer=False` stores supplied messages without inference. That does **not** establish zero embedding, query or platform cost. Current add docs describe additive storage: module-owned deduplication and temporal truth remain necessary. [Mem0 add documentation](https://docs.mem0.ai/core-concepts/memory-operations/add).

Mem0 Platform now documents a native graph based on entity co-occurrence and retrieval ranking, rather than typed relationship edges. Do not describe it as merely flat vector memory or assume old external-graph examples match the current SDK. This platform feature does not establish OSS parity. [Mem0 graph documentation](https://docs.mem0.ai/platform/features/graph-memory).

Graphiti documents temporal validity, source episodes, hybrid search and self-managed graph infrastructure. Its default setup uses inference and embedding models. Zep is a managed product with additional infrastructure; Graphiti is not a free identical deployment of Zep. [Graphiti repository](https://github.com/getzep/graphiti).

Letta's memory blocks can hold persistent context; its sleep-time approach moves processing to idle periods. Moving work off the response path does not eliminate its compute bill. [Memory blocks](https://www.letta.com/blog/memory-blocks/), [sleep-time compute](https://www.letta.com/blog/sleep-time-compute/).

## Reading the supplied Zep comparison

The page reports LoCoMo accuracy of 94.7% versus 91.6%, retrieval of 87 ms versus 3,060 ms, and context of 5,760 versus 6,956 tokens. Those are **Zep-published results**, not independently reproduced OGame measurements. A context reduction does not establish lower total ingestion-plus-generation cost. The published context sizes also exceed this plan's proposed ordinary reply budget. [Supplied comparison](https://www.getzep.com/mem0-alternative/).

## Adoption test

Use the same synthetic/consented corpus, held-out questions, reader model, token ceiling and load for every option. Include old/new alliances, an expired ceasefire, ambiguous names, false claims, fulfilled debt and deletion. Test 50, 500 and 5,000 isolated owners; include concurrent writes and cold queries.

Measure recall of required facts, wrong-current-fact rate, owner leakage, p95 retrieval latency, write amplification, total tokens and total monthly cost at the same traffic. Record all internal provider extraction/reranking calls where exposed; otherwise mark cost opacity.

Adopt an external service only if it improves required-fact recall by a proposed 5 percentage points or reduces total cost by 20% at comparable quality, with zero scope leakage and acceptable operations. These are decision thresholds to agree before testing, not claimed results. Keep native IDs and exportable facts so migration does not erase relationships.
