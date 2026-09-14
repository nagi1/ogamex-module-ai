# Memory and language

Owner: AI module memory/social. Phase 3 design: [cognition and contracts](phase-3-cognition.md). Provider selection gates: [comparison](../research/memory-comparison.md). Cost controls: [budgets](budgets.md). This is a planned feature, not an inventory of implemented tables.

Container-backed implementation, migration and verification use only the host repository's `local-docker-dev/` environment. Memory work must not start a competing Docker stack.

## Native memory is the foundation

Use module-owned tables in the existing relational database first. Reuse host module metadata only for small player/planet identity pointers; it is not the high-volume memory store. Structured event reducers require no LLM or embeddings. Suggested module-owned records:

| Record | Essential fields |
|---|---|
| Observation/event | scope, owner, source type/ID, subject, kind, permitted payload, observed_at, source_time, schema version |
| Belief/fact/claim | scope, owner, subject, predicate, value, evidence kind, speaker when claimed, source IDs, confidence, valid_from/to, expires_at, revision |
| Relationship | owner/other account, trust, threat/fear, affinity, respect, social importance, last interaction and supporting events; unresolved debt references |
| Commitment | parties, exchange ID, exact terms/units/conditions, due time, proposed/accepted/fulfilled/broken/expired/cancelled state, authorization and fulfillment references |
| Cognitive state/emotional episode | owner, persona revision, goal/affect state, prior revision, appraisal/driver version; significant episode source and emotional consequences |
| Experience case | owner, case family, decision/receipt IDs, feature/ruleset versions, observed situation, intended action, pending/final outcome and utility evidence |
| Conversation/exchange | participant/channel ACL, source message IDs, current exchange and revision, locale, bounded recent turns, pending reply generation and optional derived-summary version |
| Reply/usage/outbox | source range, context/policy revision, chosen route, generation state, provider request/usage IDs, reservation, delivery key, expiry and source projection state |

Use a configured deployment/universe scope; the inspected host has no established `universes` table to reference. Do not invent a host foreign key. Verify parent ID types before adding module foreign keys; host user IDs and chat/alliance IDs are not all the same integer size.

Unique `(scope, owner, source_type, source_id)` prevents collisions and duplicate ingestion. Derived records additionally identify their reducer/projection purpose and version. Index actual owner/subject/type/time and due-state queries. Store sparse relationships only after contact, never an N-by-N matrix. Indexes and external state are projections of module-owned records, not replacements for them.

Reducers update typed facts directly from permitted game events. Important personal losses can remain in bounded long-term history. Repeated probes become a count/time window with source references instead of thousands of prompt memories. Recompute decayed scores on read or in bounded maintenance batches; no per-second decay jobs.

Suggested salience: consequence relative to account value, relationship importance and unresolved obligation. Salience governs retention; validity governs truth. An old fleet report remains a historical event but cannot represent the current fleet. Commitments persist until fulfilled/expired; summaries cannot silently overwrite their terms. Transient anger may decay while a debt remains outstanding. Successful recall or repeated gossip must not upgrade evidence into verified truth.

Use three distinct memory purposes: native exact facts/claims/obligations; bounded emotional working autobiography for cognition; selected older episodes for long-term recall. CBR cases add situation/action/outcome evidence for competence. Avoid storing the entire event history independently in each driver. One source can support several purposeful projections with shared provenance.

## Committed sources and integration

Reuse module provider listener/observer registration and existing actor-owned records. In the inspected host, `BattleResolved` is a calculation event without sufficient durable outcome correlation, and `FleetMissionArrived` is not an intrinsic after-commit guarantee. They may prompt a later legal-state review; they cannot directly establish an actual battle result or completed commitment. Prefer a committed report delivered through an owner-scoped `Message` or a correlated core operation receipt.

`ChatMessageSent` is a broadcast event, not proof of durable transaction completion. Use module-owned after-commit observation where applicable, then reload the committed row and recheck scope. Cover missed notifications with bounded, indexed reconciliation of information the AI may legally see. Never scan unrestricted opponent state to fill gaps. Store observed time separately from event time so late learning does not grant past knowledge.

Alliance membership changes need recipient-specific authorization and time. A module observer/reconciler must cover the actual write paths and account for bulk updates that bypass observers. Only a demonstrated gap justifies a separate, generic host lifecycle hook; Phase 3 does not automatically authorize new host chat/alliance APIs.

## Retrieval and trust

Scope first by universe, owner and channel authorization, then filter subject, current validity and relevance. Retrieve unresolved commitments, recent relevant events and a capped set of older salient facts. Use database full-text search only when entity/topic filtering is insufficient. Add vector recall only after a measured miss rate justifies it.

Sharing creates a received report with sender, delay and confidence; it does not grant access to the sender's entire memory. Leaving an alliance ends access to new/private shared material; previously observed facts follow the server's normal information policy. A driver receives a scoped snapshot, and returned IDs are checked again in native storage before use. Provider metadata filters alone do not establish authorization.

Chat is untrusted evidence. “I have no fleet” becomes an attributed claim, not verified fleet state. “Raven says Draco plans to attack” preserves Raven as speaker and does not prove Draco's intent. An attack that never happens does not by itself prove Raven lied. Extracted promises are proposals until deterministic policy confirms parties, conditions and exact terms. Do not infer real-world personal profiles from human conversations.

Retention expiry, deletion or revoked access hides records from all retrieval paths, including direct-ID reads. Temporal validity is different: a ceased agreement, overdue promise or superseded fact stops being current but remains attributed historical evidence while retention and access permit it. Deletion propagates to summaries, embeddings and optional provider indexes using tombstones/outbox retries. Filter deleted/revoked IDs locally while provider deletion is pending; stale external results cannot resurrect them. Keep technical audit retention distinct from player-visible memory retention. Do not export private conversations to a provider by default without the server's configured data policy. Chat soft deletion and reply-to references require the same rechecks.

## Optional semantic recall and context compression

Begin with exact entity/topic/time filtering, unresolved obligations and relevant recent events. Use full-text only where that leaves measured misses. Only after an approved semantic experiment supplies a caller, introduce `SemanticRetriever` for candidate source IDs/scores and `Embedder` for versioned text vectors; `LongTermMemory` resolves candidates against current authorized native facts. Those future interfaces and projection jobs are not scaffolded in the baseline.

Embed eligible conversation prose once per content/model version and queries when needed. Deduplicate projection jobs; record model ID, dimensions, locale and retrieval task. Reindex on incompatible model/version changes; never compare vectors from mismatched spaces. Enforce deletion, time and owner scope in the old and new index during migration. If embedding/index work fails, native retrieval and gameplay continue.

Do not embed precise resources, fleet composition, mine levels, trust scores, promises or routine ticks. Structured CBR features use deterministic similarity. Semantic preselection of future prose-heavy cases and retrieval of authored dialogue variants are optional experiments; final case scoring and dialogue preconditions remain deterministic.

The embedding model and dimension count are now selected in the [driver decisions record](../research/phase-3-driver-decisions.md): OpenAI embeddings behind the module's `Embedder` contract, pinned to a dated snapshot, 1536 dimensions. The **storage form is still open**, because the module runs on MySQL and the pgvector `halfvec`/`vector` comparison does not apply on that platform. A network call on the recall path is accepted; a provider outage degrades recall to the lexical path and never fails a request. The snapshot is pinned because an embedding model can change silently under a stable name, and changing it is a migration.

**English is the only supported language**, decided by the owner on 14 September 2026. Authored replies, the evaluation corpus and message interpretation are English-only, and no locale setting is carried: a second language means authored content and a selection input, not a configuration value. The earlier requirement to evaluate English, Arabic and mixed-language recall is dropped as a scope decision rather than met, and reinstating it is a feature with its own content and tests.

The `ContextBuilder` selects, ranks, trims and serializes before any compression. Include only stable persona, current affect/stance and relevant goals, authorized fact IDs/terms, selected memory, recent turns, legal communication constraints and the current human input. Section and total budgets include schema/wrapper overhead. Do not ask a model to rediscover emotions from a full chat history.

The initial `ContextBuilder` trims older prose deterministically or leaves already-bounded prose alone. A separate `ContextCompressor` contract is introduced only with a real approved compression experiment, with a null/disabled fallback. Protect system instructions, legal constraints, the current turn, speaker attribution, confidence, exact commitments and source IDs. If protected content exceeds the hard budget, choose a bounded clarification/template or defer; do not silently remove terms. ML compression such as LLMLingua is only a later measured experiment on older prose and must preserve these invariants.

## Spend language tokens only on useful interactions

Message received → rate limit/deduplicate → determine whether/when to answer → coalesce pending turns → recognize/evaluate exchange → choose resolution route → retrieve scoped context → reserve budget if needed → generate once if needed → validate → recheck permission/current state → send through normal messaging.

Use authored templates for acknowledgements and routine messages. Known substantive social exchanges first use affect/social cognition and authored dialogue. An LLM is selective language realization or interpretation for unrestricted human conversation; “substantive” alone is not a trigger. No background LLM conversations between automated accounts: use structured exchanges and occasional authored public text. Human-requested replies take priority over unsolicited chatter.

When enabled, this one LLM call is made through the module's `LanguageGateway` Laravel AI SDK adapter, not a custom provider HTTP client. The agent receives only the serialized `ContextBuilder` result and has structured output but no tools or conversation-memory trait. Laravel AI's own queue, broadcast and conversation-store facilities do not replace the module's leased reply job, receipt ledger, host-chat source authority or permission recheck. The full dependency, schema, telemetry and test plan is [Laravel AI SDK integration](laravel-ai-sdk.md).

The model receives compact persona/cognition, authorized facts with IDs, latest turns, permitted communication and the policy's intent/constraints. The response envelope contains text, a proposed interpretation/intent when needed, and optional bounded claim/fact/commitment candidates with source message IDs. Counts, types, lengths, entities, dates and units have explicit schema limits. Reject unknown fields/actions and malformed or truncated output; no automatic repair-generation chain.

Validate each candidate's attribution, scope, evidence kind, supported terms and existing authorization. The model may propose an apology or agreement; it cannot set trust, invent persona traits or accept a commitment itself. Run an interpreted exchange through the deterministic social policy. If the final response no longer matches the validated intent or terms, send an authored clarification/refusal or remain silent. Do not pay for a second interpretation, extraction or realization call to finish the turn.

No tools, database handles or executable actions are exposed to chat generation. Ignore instruction attempts inside player messages and stored memories. Ground coordinates, resource amounts and promises in allowed fields; fall back to a factual template or silence on invalid output. Never claim a transport occurred until a core receipt confirms it.

If a provider is slow or unavailable, do not hold the player worker. Expire stale reply jobs, apply a single bounded retry where appropriate and keep deterministic gameplay running. Do not clear a player's identity or history when rotating providers.

## Reply lifecycle, batching and concurrency

Keep a separate conversation queue and module-owned pending replies/receipts; reuse proven lease/generation patterns without holding the gameplay job while waiting for a network response. A pending reply identifies the owner, channel/participants, source range, exchange revision, generation, selected route, due time and expiry.

Coalesce only messages in the same authorized conversation and bounded idle window. Limit maximum age, number of turns and input size so an active sender cannot postpone a reply forever. Messages arriving after a request is sealed advance the pending generation; validate whether the in-flight reply is still useful before delivery. Never mix private conversations or multiple AI identities into one context to save tokens.

Reserve before dispatch, record request identity, validate/settle usage, then deliver through a stable delivery key. Duplicate queue delivery must not generate or send twice. An uncertain network timeout may already be billable: reconcile by provider request identity when possible, retain the reservation and never blindly resend. One bounded retry is permitted only when safe and still within the shared attempt cap; no automatic multi-provider retry cascade.

Persist a delivery receipt and core chat-message ID atomically where the existing shared database path permits it. Test crashes around generation, persistence and broadcast. An uncertain send must be reconciled before retry. Internal source rows/proposals become durable only after commit; a broadcast alone cannot fulfill an agreement. No sent or generated message proves that a fleet/transport actually executed.

Conversation-idle/end processing may finalize episode boundaries, aggregate known structured facts and enqueue eligible projection jobs in bounded batches with zero generative calls. A provider Batch API for later selected summaries/learning is a distinct, disabled experiment; it does not follow automatically from coalescing. Its activation and cost rules are in [budgets](budgets.md).

## Host-equivalent message delivery

At the inspected host revision, `ChatService::sendDirectMessage` and `sendAllianceMessage` persist/broadcast messages but do not enforce every controller permission check. The module's planned `DeliverAiReplyAction` must resolve a fresh actor and use the existing host checks for recipient existence, self-message rejection, ignore status and alliance membership, plus host-equivalent nonblank/length validation, before calling the normal send service. Add an explicit module guard that the reply-to target belongs to this authorized conversation and remains visible; the inspected host controller does not establish that guard, so do not assume it exists.

Recheck after generation: the recipient may have blocked the AI, either participant may have left the alliance, a source message may be deleted, an agreement may have expired, or the module/account may be disabled. Reject or safely replan stale replies without another automatic model call. No message is sent using controller impersonation or a new AI-specific host endpoint.

Tests must exercise real chat persistence and these permission changes, including two sequential actors in one worker. They must compare the AI adapter with the rules enforced by the human path. Do not use `ChatService` alone as evidence that an unauthorized send is impossible.
