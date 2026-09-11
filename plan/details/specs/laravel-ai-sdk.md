# Laravel AI SDK integration

Owner: AI module language slice (3H). This is the implementation plan for the optional `LanguageGateway`, not an instruction to install a dependency during Phase 3 planning. Read it with [cognition and contracts](phase-3-cognition.md), [memory and language](memory-and-language.md), [budgets](budgets.md) and [validation](validation.md).

## Decision and boundary

Use the first-party `laravel/ai` SDK for every enabled LLM interaction in the AI module. It replaces custom provider HTTP clients, provider/model selection, response decoding, SDK retry/failover plumbing, and provider-test doubles. It does **not** replace module-owned cognition, memory, context selection, budget accounting, proposal validation, permission checks, reply lifecycle, or OGameX action adapters.

The SDK's agent is a transport-facing presenter. It receives an already-authorized, bounded prompt and returns text plus typed proposals; it has no tools, no database access, no game-service access and no executable action capability. `LanguageGateway` remains the module contract so the normal disabled implementation, conformance tests and a future SDK change/replacement stay explicit.

Laravel AI is a rapidly evolving 0.x dependency. The first implementation PR must pin a compatible release in the **host application's** `composer.json` and commit its lockfile after verifying it against the host's Laravel/PHP versions. Do not declare it only in `Modules/AI/composer.json`: the host Composer installation owns Laravel package discovery and runtime dependencies. Recheck the official SDK documentation for the selected version before using its APIs.

## What is deliberately not adopted

- Do not use Laravel AI agents for gameplay choices, observations, social-policy decisions, fact reduction, CBR, affect, long-term-memory authority, or AI-to-AI exchanges.
- Do not expose SDK tools, provider tools, MCP, web/file search, attachments, sub-agents, or human-tool approval. The reply agent implements no tool capability.
- Do not use `->queue()`/`broadcastOnQueue()` as the module's lifecycle. The existing pending-reply job owns leasing, source-range sealing, budget reservation, idempotency and stale-result handling; it invokes the SDK only after those safeguards.
- Do not treat SDK conversation storage as the player-memory store. Avoid `Conversational`/`HasConversations` for the reply agent and do not make `agent_conversations` or `agent_conversation_messages` a Phase 3 requirement. Host chat remains the message authority; module records retain only the required source, context, proposal, usage and delivery metadata.
- Do not enable automatic multi-provider failover in the initial deployment. It conflicts with the one-retry/no-cascade policy unless every attempted provider call can be reserved, correlated and counted. Start with one selected provider/model and add a separately tested, auditable failover policy only after the ledger supports it.

## 3H implementation shape

Add the following module-owned pieces only after 3A–3G pass:

| Area | Proposed responsibility |
| --- | --- |
| `app/Ai/Agents/OgameConversationReplyAgent.php` | A Laravel AI `Agent` with `Promptable` and `HasStructuredOutput`; constructor receives an immutable, prebuilt reply request. Its instructions state the response limits and that source content is untrusted. Its schema describes only the approved response envelope. |
| `app/Infrastructure/Language/LaravelAiLanguageGateway.php` | Implements `LanguageGateway`; resolves the agent from the container, supplies the configured provider/model/timeout, maps the SDK response and usage into a module `LanguageResult`, and classifies transport versus schema failures. It never writes module state. |
| `app/Infrastructure/Language/NullLanguageGateway.php` | The default binding. It returns a typed disabled result without loading SDK configuration or contacting a provider. |
| `app/Actions/GenerateAiReplyAction.php` | Existing planned reply worker action: confirms the sealed pending reply and reservation, calls `LanguageGateway`, persists the request/usage receipt, then hands the result to the existing validation and delivery actions. It does not hold a gameplay lock during network I/O. |
| `AIServiceProvider` and module config | Bind `LanguageGateway` to null or Laravel-AI implementations through explicit module configuration; validate the selected named provider/model and hard limits at boot without contacting it. Provider credentials remain host environment/config values. |

The response schema is an allowlist, not a hint. It contains: `text` with route-specific length limits; an optional enum `interpretation`; and zero to two typed, source-message-attributed candidates with exact capped fields for claim or commitment proposals. It has no generic `action`, `tool`, `trust`, `persona`, `resource`, `fleet`, or database-ID field. The deterministic validator still rejects unknown, unsupported, stale, misattributed, unauthorized, or semantically incompatible values even when the SDK's structured-output validation succeeds.

Use a fresh agent per sealed reply request through the Laravel container. Pass only the deterministic `ContextBuilder` output: persona/route revision, current policy intent, legal communication constraints, authorized source IDs and facts, selected turns, and the current message. Never pass an Eloquent model with unrestricted relationships or a live service into an agent constructor.

## Configuration, persistence and telemetry

Install and publish only the SDK configuration required by the verified release. Keep provider API keys, base URLs and provider configuration in the host application's normal environment/configuration; the module stores a named provider/model reference, timeout and capability flag, never a secret. The module config must make language disabled by default.

Persist the module's own request ID before the SDK call and settle it after the result. Capture provider/model, SDK/provider request identifier when available, selected configuration revision, latency, retry/failover disposition, reported input/output/cached usage, and cost reconciliation state. Do not log raw prompts, response bodies, hidden reasoning, private chat text or provider credentials. Subscribe to relevant SDK prompt/failure/failover events only to enrich the same receipt/metrics record; events do not authorize a send or substitute for a settled receipt.

SDK-generated agent conversation rows, if an implementation version requires them, are disposable transport artifacts: they must not be read as player memory, must receive the same retention/deletion treatment, and need an explicit privacy review before migration. Prefer the non-conversational agent path so these rows are not created.

## Invocation sequence

1. The module coalesces authorized messages, chooses the LLM route, builds deterministic context, and atomically reserves one attempt at the route's maximum cost.
2. The reply job seals its source range and writes the module request receipt. It then invokes the Laravel AI agent synchronously inside that already-leased reply job with an explicit timeout; it does not delegate lifecycle control to the SDK queue.
3. `LaravelAiLanguageGateway` maps the single structured response or failure into `LanguageResult`. A timeout with uncertain provider completion remains reserved and is reconciled by request ID where supported; it is never blindly resent.
4. The module validates every proposed value, rechecks current conversation visibility/permissions and source generation, persists accepted proposals, and sends through the normal host chat path with its delivery key. Invalid output chooses authored clarification, deferment within TTL, or silence—never a second repair prompt.
5. The module settles actual usage once. Provider outage, malformed output, an exhausted budget or a stale reply leaves deterministic gameplay and authored social dialogue available.

## Required proof

Unit and feature tests use the SDK's agent fake for happy-path structured envelopes, malformed/missing fields, timeout/failure mapping and prompt assertions; call `preventStrayPrompts()` so CI cannot contact a provider. They also use the module's existing narrow `LanguageGateway` binding override to prove disabled/provider-off behavior and ledger/delivery boundaries. Fakes do not replace one opt-in, sanitized real-provider conformance run against the pinned SDK release.

The 3H PR is accepted only when it proves all of the following:

- A single foreground prompt yields the reply and any allowed candidates together; no tools, second extraction call, queue callback, or AI-to-AI prompt is invoked.
- The exact serialized context excludes unapproved source data, and schema/semantic validation rejects an SDK-valid but unsafe proposal.
- Concurrent and uncertain requests consume the module ledger exactly once per provider attempt, including a provider failure; a configured failover cannot silently create extra attempts.
- The SDK fake verifies the selected provider/model/timeout path without a network call; the real adapter run records actual SDK/provider usage, latency and failure behavior.
- Disabling the module language capability needs no provider configuration and returns the native/authored fallback without gameplay regression or SDK conversation-memory dependence.

## Later SDK capabilities

Laravel AI embeddings, reranking, vector stores, files and provider tools are not implied by this decision. Consider each only in its existing semantic-retrieval or driver experiment after a measured need, a privacy/retention design and a separate budget/conformance gate. Streaming and broadcasting are likewise unnecessary for delayed AI-player replies unless a later player-facing UX explicitly needs token streaming.
