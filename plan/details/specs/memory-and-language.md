# Memory and language

Owner: memory/social. Provider choice: [comparison](../research/memory-comparison.md). Cost controls: [budgets](budgets.md).

## Native memory is the foundation

Use module-owned tables in the existing relational database first. Reuse host module metadata only for small player/planet identity pointers; it is not the high-volume memory store. Structured event reducers require no LLM or embeddings. Suggested module-owned records:

| Record | Essential fields |
|---|---|
| Observation/event | universe, owner, source ID, subject, type, payload, observed_at, source_time |
| Belief/fact | owner, subject, predicate, value, source IDs, confidence, valid_from/to, expires_at, version |
| Relationship | owner/other account, trust, threat, affinity, debt, last interaction, supporting events |
| Commitment | parties, exact terms, due time, status, authorized action reference |
| Conversation | participant/channel ACL, message IDs, recent bounded turns, optional summary version |

Unique `(universe, owner, source_id)` prevents duplicate ingestion. Index retrieval by owner/subject/type/time. Store sparse relationships only after contact, never an N-by-N matrix.

Reducers update typed facts directly from permitted game events. Important personal losses can remain in bounded long-term history. Repeated probes become a count/time window with source references instead of thousands of prompt memories. Recompute decayed scores on read or in bounded maintenance batches; no per-second decay jobs.

Suggested salience: consequence relative to account value, relationship importance and unresolved obligation. Salience governs retention; validity governs truth. An old fleet report remains a historical event but cannot represent the current fleet. Commitments persist until fulfilled/expired; summaries cannot silently overwrite their terms.

## Retrieval and trust

Scope first by universe, owner and channel authorization, then filter subject, current validity and relevance. Retrieve unresolved commitments, recent relevant events and a capped set of older salient facts. Use database full-text search only when entity/topic filtering is insufficient. Add vector recall only after a measured miss rate justifies it.

Sharing creates a received report with sender, delay and confidence; it does not grant access to the sender's entire memory. Leaving an alliance ends access to new/private shared material; previously observed facts follow the server's normal information policy.

Chat is untrusted evidence. “I have no fleet” becomes `speaker_claim`, not verified fleet state. Extracted promises are proposals until deterministic policy confirms terms. Do not infer real-world personal profiles from human conversations.

Expiration hides facts from all retrieval paths, including direct-ID reads. Deletion propagates to summaries, embeddings and optional provider indexes using tombstones/outbox retries. Keep technical audit retention distinct from player-visible memory retention. Do not export private conversations to a provider by default without the server's configured data policy.

## Spend language tokens only on useful interactions

Message received → rate limit/deduplicate → determine whether/when to answer → coalesce pending turns → retrieve scoped context → reserve budget → generate once → validate → send through normal messaging.

Use authored templates for acknowledgements, structured trade offers and system-like notices. Use an LLM for substantive human conversations where variation and context matter. No background LLM conversations between automated accounts: use structured exchanges and occasional authored public text. Human-requested replies take priority over unsolicited chatter.

The model receives a compact persona, authorized facts with IDs, latest turns and the policy's communicative intent. Output contains proposed reply text and optionally fact-extraction candidates in a bounded schema. Validate these candidates; do not run a second extractor for every turn. Preserve raw messages for later clarification if summarization is skipped.

No tools, database handles or executable actions are exposed to chat generation. Ignore instruction attempts inside player messages and stored memories. Ground coordinates, resource amounts and promises in allowed fields; fall back to a factual template or silence on invalid output. Never claim a transport occurred until a core receipt confirms it.

If a provider is slow or unavailable, do not hold the player worker. Expire stale reply jobs, apply a single bounded retry where appropriate and keep deterministic gameplay running. Do not clear a player's identity or history when rotating providers.
