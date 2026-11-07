# Package S — LLM full utilisation (side work package)

**Status:** shipped 16 September 2026 — S1–S7 done (see below); **17 September** — S4b done
(all six campaign triggers now have a signal: new phase, contested objective, fleet loss, repeated
setback, coalition conflict, rank change) and S8 done for the consultation lane (`CampaignFactsTool`
+ `LegalCandidatesTool`; `CounterpartyFactsTool`/`HostCapabilityTool` deferred until their lanes
exist). The whole Package S is shipped; see the slice sections for the deferred follow-ups. **17
September (DISC-008)** — the LLM-bot survey's sixteen learnings were scored against the shipped
package: one correction slice (S9, the cache-read token accounting S6 left unfed) and a recorded set
of refusals; see the S9 section.

**Shipped:** S1 prompt review + `PromptGateOneTest` gate-1 guard · S2 `llm-prompt-knowledge.md` ·
S3 language lane enabled by default (`deepseek-flash` + `gpt-5.6-luna` fallback) · S4 the
consultation lane wired (NewPhase + ContestedObjective signals → one consult per session decision →
profile-bounded nudge) · S5 thinking-model routing (`deepseek-v4-pro` with thinking mode for
consultations) · S6 priced model matrix + settled dollar cost in the pilot report and operability
overview · S7 `Http::fake` fixture replay of the real DeepSeek response shape plus the capture script.

**Owner direction:** 16 September 2026 — "enhance the Laravel AI agents, pull
smart-bot knowledge into the prompts, enable the LLM by default and wire everything that was planned
for it, finish Lane 2, default to DeepSeek Flash with an OpenAI Luna fallback, add a
thinking-model / mixture mode, and measure LLM feasibility end to end."

This package is **on-command**: it starts only when the owner says so, and runs beside Package 6/7.
It touches the provider/language lane, not ordinary-universe gameplay authority, so it is not gated by
Package 6 acceptance. The one exception is S4, which needs Package 6's campaign decision points — those
deterministic slices already shipped (16 September 2026), so S4 has its caller to wire.

Every slice follows the three cognition gates and the rung ladder. Gate 1 is the live risk here: an LLM
prompt must carry **how a professional reasons**, never the object universe. Object ids, machine names,
prices and requirements stay host-read and arrive in the request context, never in authored prompt text.

## Verified facts this package builds on (do not re-research)

- **SDK.** `laravel/ai` `^0.11` is pinned in the **host** `composer.json` (not the module). It ships
  `DeepSeekProvider`, `OpenAiProvider`, `OpenRouterProvider` and `OpenAiCompatibleProvider`. Its
  gateways build clients with `Http::baseUrl(...)` — the Laravel `Http` facade — so `Http::fake()`
  intercepts provider traffic in tests. An agent may implement `HasProviderOptions::providerOptions()`
  to merge arbitrary per-provider body fields (this is the only seam for `thinking` /
  `reasoning_effort`). Failover is the SDK walking an ordered provider map; the module owns the order.
  There is **no router, cascade or "mixture" feature in the SDK** — `cheapestTextModel()` /
  `smartestTextModel()` are per-provider accessors, used only by conversation compaction.
- **Model names (DeepSeek and OpenAI docs, fetched 16 Sep 2026).**
  | Role | Model name | Version | Notes |
  | --- | --- | --- | --- |
  | Default (cheap/fast) | `deepseek-flash` | DeepSeek-V4.1-Flash | the "Flash 4.1"; legacy `deepseek-v4-flash` retired but still accepted |
  | Thinking / hard problems | `deepseek-v4-pro` | DeepSeek-V4-Pro-0813 | thinking mode via `"thinking":{"type":"enabled"}` + `reasoning_effort` |
  | OpenAI fallback | `gpt-5.6-luna` | GPT-5.6 Luna | fast, affordable tier |
- **Pricing (2026 docs, per 1M tokens).** DeepSeek `deepseek-flash`: input $0.15 off-peak / $0.30
  peak (cache hit $0.003 / $0.006), output $0.60 / $1.20. `deepseek-v4-pro`: input $0.66 / $1.32,
  output $1.98 / $3.96. OpenAI `gpt-5.6-luna`: input $0.20, cached $0.02, output $1.20 (under 272K
  context). DeepSeek peak hours are 01:00–04:00 and 06:00–10:00 UTC, Monday–Friday — already encoded
  in `config/routing.php`.
- **The SDK's baked DeepSeek defaults are stale** (`deepseek-v4-flash` / `deepseek-v4-pro` in
  `DeepSeekProvider`). The module overrides the model per request; no vendor edit.

## Slices

### S1 — Prompt review + token-lean context (point 1)

Review the two agents and their prompts for what the model actually needs, then cut everything else.

- Files: `app/Ai/Agents/OgameConversationReplyAgent.php`, `app/Ai/Agents/OgameCampaignConsultationAgent.php`,
  `app/Actions/BuildCampaignConsultationBriefAction.php`, `app/Actions/GenerateAiReplyAction.php`
  (context sections), `app/Infrastructure/Language/LaravelAiLanguageGateway.php`.
- Rules applied:
  - Instructions state *how to reason and what is untrusted*, never object lists, never syntax narration.
  - Context carries only: route constraints, authorized source message ids, persona/archetype + skill
    band, exact current terms, and the current turns. Everything else is omitted, not truncated.
  - The schema is an allowlist: no generic `action`/`tool`/`fleet`/`resource-id` field; every field the
    deterministic validator already rejects stays out of the schema rather than being "hinted away".
  - One foreground request stays one request — no separate extraction/classification/judge chain.
- **Proof:** a conformance run reports per-request input/output tokens against the `ai.language` /
  `ai.campaign-consultation` budgets **before and after** the rewrite (a table, not prose). CI keeps
  `preventStrayPrompts()`; `LaravelAiLanguageTest` / `CampaignConsultationGatewayTest` stay green with
  no weakening of `RecordAiLanguageProposalAction` / `RecordCampaignConsultationAction` validation.

### S2 — Bot and strategy research for prompt grounding (point 2)

Extend the existing bot/strategy research with a distillation pass aimed at **what the agents may
reference**, not at new gameplay slices.

- Input: `plan/details/research/ogame-automation-algorithms.md` (sixteen projects, P1–P13),
  `strategy-principles.md` (107 principles / 13 domains), `veteran-play.md`,
  `strategy-simulation-and-bot-patterns.md`.
- New artifact: `plan/details/research/llm-prompt-knowledge.md` — a compact, prompt-safe statement of
  the tactics, prioritization heuristics and self-similarity rules an experienced player follows,
  written so an agent can hold it in a system prompt. Each entry names its source and is phrased as
  reasoning ("prefer the easiest unlock that opens the most next", "save before the fleet returns,
  but a save that can fail is fine") — never as an object id, machine name, price or requirement.
- **Gate 1 is the acceptance test:** a test asserts no prompt artifact contains a hardcoded object id /
  machine name / price. The host catalogue stays the only source of object truth.
- **Proof:** `llm-prompt-knowledge.md` is referenced by S1's prompts; a `PromptGateOneTest` proves the
  serialized prompts contain no object-universe list.

### S3 — Enable the LLM by default + verified model names (points 3 and 5)

- Default `ai.language.enabled` to `true`; default provider `deepseek`, model `deepseek-flash`;
  fallback ladder rung `openai` / `gpt-5.6-luna`.
- Update `config/language.php` defaults, `config/routing.php` ladders (replace `gpt-5-mini`), and
  `ResolveAiProviderRouteAction::configuredPair()` default model.
- Keep the authored-text fallback as the floor: every provider-off, failed, timeout and invalid
  outcome still delivers the sealed authored reply. AI-to-AI and greeting/thanks still never escalate.
- **Flag for owner:** this reverses the fail-closed default that shipped with 3H. It must ship with the
  budget ceilings already in `config/language.php` and a green `AblationHarnessTest` proving native /
  provider-off play is byte-for-byte unchanged.
- `ai.campaign-consultation.mode` stays `off` until S4 lands and is measured.

### S4 — Wire Lane 2 (campaign consultation) correctly (point 4)

The lane is complete and tested but has **no production caller**:
`RequestCampaignConsultationAction` and `ApplyCampaignConsultationRankingAction` are invoked only from
tests today.

- Add the six `AiCampaignConsultationTrigger` sites at the real campaign decision points
  (`AdvanceAiCampaignStateAction` / objective resolution / the campaign director), one trigger per
  material event: fleet loss, repeated setback, contested objective, coalition conflict, new phase,
  rank change.
- Wire: admission (`ResolveCampaignConsultationAdmissionAction`, `off`/`observe`/`advice`) → cooldown →
  concurrency cap → brief → `RequestCampaignConsultationAction` → validated recommendation →
  `ApplyCampaignConsultationRankingAction` as a `selectionMargin()`-bounded nudge in the campaign
  candidate ranking. Native policy still chooses and dispatches; the recommendation can never name a
  candidate outside the supplied list, override a refusal, or touch an unavailable action.
- `observe` records without applying; `off` never resolves the SDK or contacts a provider.
- **Proof:** a campaign fixture drives a consultation end to end and a validated recommendation moves
  only an already-legal candidate within its profile bound; `off` and every failure path make zero
  provider calls and preserve the native decision. `CampaignConsultationRequestTest` / `RankingTest`
  move from "action unit" to "end-to-end caller" coverage.

### S4b — the four triggers that needed a signal of their own (17 September 2026)

`NewPhase` (in `AdvanceAiCampaignStateAction`) and `ContestedObjective` (in
`ResolveAiCampaignObjectiveFromBattleReportAction`) already fired from the deterministic campaign
slices. S4b wired the remaining four, each as one `RecordCampaignConsultationSignalAction` call for
the active campaign(s), consumed by the next faction session decision exactly like the first two:

- **FleetLoss** — in `RecordObservedBattleReportAction` (the battle observer): the one faction side
  of a committed report ended the last round with zero ships while the other side kept survivors.
- **RepeatedSetback** — the same observer cascades when the campaign has accumulated
  `REPEATED_SETBACK_LOSSES` (2) fleet-loss signals: the second defeat is the moment a professional
  player stops repeating a failed defence and reconsiders.
- **CoalitionConflict** — the same observer: a committed report whose two sides are both coalition
  (neither an enabled faction profile) is coalition infighting.
- **RankChange** — in `RecordAiScoreSamplesAction`: a faction account's `general_rank` moved between
  two hourly samples (both ranks non-null). Rank data only exists while score sampling is on
  (`ai.review.enabled`), the same gate that runs the pass.

No schema change: all six triggers live on the existing `AiCampaignConsultationSignal` table.

### S5 — Thinking-model / "mixture" routing (point 6)

**Interpretation (flag for owner):** "mixture of experts" is a model architecture, not a call mode —
DeepSeek V4 is MoE internally and the SDK has no router feature. What we can build is a **per-task
model route plus an escalation cascade**, which is the smallest mechanism that uses a thinking model
where it pays and the cheap model everywhere else.

- Route by task kind in `ResolveAiProviderRouteAction`: `ConversationReply` → `deepseek-flash`;
  `CampaignConsultation` → `deepseek-v4-pro` with thinking mode enabled.
- Enable thinking mode through the agent's `HasProviderOptions::providerOptions()` returning
  `['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'high']` for the DeepSeek driver (this is
  the SDK's supported seam; verified against `TextGenerationOptions::providerOptions()`).
- Optional bounded cascade: if the cheap model returns a schema-valid but low-confidence result (a
  module-defined signal, e.g. `interpretation === Uncertain` or an empty candidate set on a substantive
  message), make **one** extra attempt on the thinking model, charged as a second provider attempt in
  the ledger. No cascade on provider failure (that is the SDK's failover, not escalation).
- **Proof:** routing tests per task kind; a request-body assertion that the thinking rung carries
  `thinking`/`reasoning_effort`; the cascade is counted exactly once in `ai_usage_reservations`; the
  failover (SDK) and the escalation (module) are distinguishable in the receipt.

### S6 — Priced model matrix + LLM feasibility and reporting (point 7)

Turn the prose prices into a config-driven matrix and make reporting settle real dollar cost, not just
tokens.

- **Pricing matrix (config).** A `pricing` key beside `config/routing.php` (or a new `config/pricing.php`)
  maps `provider.model` → `{input, cached_input, output}` per 1M tokens, with the peak/off-peak split
  derived from the vendor windows already in `config/routing.php`. Seeded with the verified rates:
  `deepseek-flash` $0.15 / $0.003 / $0.60 off-peak, `deepseek-v4-pro` $0.66 / $1.98, `gpt-5.6-luna`
  $0.20 / $0.02 / $1.20. Rates are config, not code, so a vendor repricing is a config diff.
- **Cost resolver.** One action (`ResolveAiUsageCostAction`) computes the dollar cost of a settled
  request from `(provider, model, input/cached-input/output tokens, settled_at)` using the matrix and
  the window gate. Unknown model or missing rate fails closed — no cost claimed rather than a wrong one.
- **Record it where usage is already settled.** `SettleAiUsageReservationAction` records `cost` on
  `AiUsageReservation`, and the `AiLanguageRequest` / `AiCampaignConsultationReceipt` writes carry the
  same figure, so every later report reads one number instead of recomputing.
- **Accurate reporting.** `ai:pilot-report` (`BuildAiPilotReportAction`) and
  `SummarizeAiOperabilityAction` report settled dollar cost — total and per provider/model — beside the
  attempts and tokens they already report, so "what the provider cost" is a real figure, not an attempt
  count. Reasoning tokens are billed output and stay in the output column, never hidden.
- **Feasibility section.** Enrich `plan/details/specs/budgets.md` with the measured cost/latency model:
  the illustrative arithmetic replaced by matrix rates × the two call profiles (reply vs. consultation),
  plus per-model latency p50/p95 from the conformance harness.
- **Proof:** a conformance run settles real requests and the receipt/ledger `cost` equals the manual
  matrix arithmetic for the same token counts; peak vs off-peak, cache-hit and unknown-model cases are
  covered; `ai.review.enabled` adds none of it to the read path.

### S7 — Real-response `Http::fake()` fixtures (point 8)

- Capture real provider responses (a one-time, sanitized conformance run or recorded traffic) as JSON
  fixtures under `tests/Fixtures/` — DeepSeek and OpenAI, structured + error + timeout-shaped.
- Because the SDK uses the `Http` facade, `Http::fake()` replays them with zero network and full
  response fidelity; feature tests for S1/S3/S4/S5 assert the module's mapping against **actual
  payload shapes** rather than hand-written envelopes.
- Keep the SDK agent fake for malformed/negative cases and `preventStrayPrompts()` so CI can never
  contact a provider. A fixture is a recorded response, never a live call.
- **Proof:** `LaravelAiLanguageTest`, `CampaignConsultationGatewayTest`, routing and cascade tests run
  against the fixtures; `preventStrayPrompts` fails the suite if any test escapes the fake.

### S8 — Read-only tool set replacing prompt-stuffing (owner, 17 September 2026)

Instead of serializing every fact the model might need into one bounded prompt, give the decision
agents a small set of read-only tools the model can call to pull exactly the facts it is weighing.
The study is [laravel-ai-tools.md](../research/laravel-ai-tools.md).

**Shipped 17 September 2026 (consultation lane first):** `CampaignFactsTool` (the campaign's phase,
window and stronghold counts, scoped to the campaign being consulted on) and `LegalCandidatesTool`
(the legal candidates with their native scores and reasons). The consultation agent now implements
`HasTools`, and the serialized brief shrinks to the campaign id — the current turn only.

- Each tool is one class implementing `Laravel\AI\Contracts\Tool`: a word-level `description()`, an
  allowlist `schema()`, and a bounded read-only `handle()` that reads the host catalogue and module
  tables at call time — never a hardcoded object list, never a mutation, never an authority.
- The base prompt shrinks to instructions + the current turn; the deterministic validator still
  re-checks every value the model proposes, so a tool returns evidence and the module keeps the
  decision.
- DeepSeek and OpenAI both document tool calls; every tool step is billed in the same foreground
  request, so S6's cost model counts tool steps rather than assuming one completion.
- The reply agent keeps its no-tools posture; tools belong to the consultation lane first.
- **Proof:** `CampaignConsultationToolsTest` drives both tools against real module state and asserts
  the brief carries only the campaign id; the consultation end-to-end tests stay green against the
  shrunk brief. The live tool-call round trip is covered by the S7 fixture-replay discipline when the
  lane goes on; `preventStrayPrompts` and the S7 fixtures keep the tests offline.

**Deferred:** `CounterpartyFactsTool` and `HostCapabilityTool`. The reply lane keeps its no-tools
posture and the PvE director is not built, so neither has a consumer yet — building them now would be
gate-2 dead code. They land with the lanes that actually read counterparty memory (reply) and host
capability (PvE director).

### S9 — settle the cache-read tokens the SDK already reports (DISC-008, 17 September 2026)

The repo survey ([`repos/llm-ogame-ai.md`](../research/repos/llm-ogame-ai.md)) found exactly three
OGame projects that genuinely call a model. Its sixteen learnings were scored against what S1–S8
already ship: fifteen are already implemented, or refused by plan doctrine, or a gated deferral
already recorded elsewhere. One is a real gap in a shipped slice, and closing it means feeding a
parameter S6 already priced.

**What is wrong.** `LaravelAiLanguageGateway` reads only `usage->promptTokens` and
`usage->completionTokens`. The SDK's `Usage` also carries `cacheReadInputTokens`, and **both** parsers
already subtract the cached part out of `promptTokens` (DeepSeek `ParsesTextResponses.php:86`,
`prompt_tokens − prompt_cache_hit_tokens`; OpenAI `:142-148`, `input − cached − cacheWrite`). A
settled request therefore records the *uncached* input alone: cached input is invisible in the ledger,
never charged at the `cached_input` rate `config/pricing.php` already holds, and no lane can report a
cache-hit rate. `SettleAiUsageReservationAction` passes a hardcoded `0` for the cached parameter that
`ResolveAiUsageCostAction` accepts and prices.

- **Smallest fix.** Carry `cacheReadInputTokens` from the response into settlement, record it beside
the token columns, count it in the input ceiling, and report the hit rate beside tokens and cost.
  Files: `app/Infrastructure/Language/LaravelAiLanguageGateway.php` (both usage reads),
  `app/Actions/SettleAiUsageReservationAction.php`, its four callers (`GenerateAiReplyAction`,
  `RequestCampaignConsultationAction`, `ReconcileAiLanguageRequestsAction`),
  `app/Models/AiUsageReservation.php` + one migration, and the two reporting actions
  (`BuildAiPilotReportAction`, `SummarizeAiOperabilityAction`).
- **Proof.** A replayed fixture carrying `prompt_cache_hit_tokens` settles a cost equal to the manual
  arithmetic (`uncached × input + cached × cached_input + output × output`) and the reservation records
  the hit count; a response without the field is unchanged; the input ceiling counts
  `promptTokens + cacheReadInputTokens`; `ai:pilot-report` prints the hit rate per provider/model. It is
  also the measurement the prefix-stability question needs — a stable instruction prefix is exactly what
  a provider's automatic caching serves, and today nothing observes it.
- **Not plumbed:** cache *write* tokens. DeepSeek and OpenAI bill cached reads only, so a write-token
  column would be a third token category nothing charges. Recorded as deliberately ignored (gate 2),
  not built.

**Scored learnings (S1–S8 against the survey).**

| Survey learning | Verdict | Where |
| --- | --- | --- |
| Strict JSON output contract + closed action vocabulary | shipped | proposal/consultation validators |
| Taste in the system prompt, never object ids or prices | shipped | S1 + S2, `PromptGateOneTest` |
| Periodic planner separated from the cheap per-message call | shipped | event-triggered consultation vs. per-message reply |
| Advisory-only: a deterministic gate decides, never the model | shipped | profile-bounded nudge in `ApplyCampaignConsultationRankingAction` |
| Summarised snapshots, never raw state | shipped | S1 token-lean context; S8 shrinks the brief to the campaign id |
| Skip the call on a trivial turn | shipped | trigger allowlist + cooldown + `off` default; authored route for greetings |
| Small model per decision, tiered by task | shipped | S5 routing |
| `max_tokens` caps + JSON mode | shipped | `config/language.php`, `config/campaign-consultation.php` |
| Plan once, execute deterministically several times | shipped | native policy executes; consultation refreshes on events |
| Deterministic fallback on every failure path | shipped | sealed authored reply; native decision on any non-completed status |
| Truncation budgets (`top-5`, `[:200]`) | better than the survey | S1 omits rather than truncates |
| Self-reflection: transcript → summary → next-cycle directive | **refused** | doctrine already forbids periodic reflection and per-event summaries (`budgets.md`, Package 7 "do not build"); the six deterministic trigger signals carry what materially changed |
| "Strategic RAG" by keyword scoring | **refused** | the survey itself flags it as mislabelled; memory mechanisms are specified in [`agent-memory-tooling.md`](../research/agent-memory-tooling.md) |
| Provider Batch API for summaries | already-gated deferral | [`budgets.md`](budgets.md) "Coalescing, batches and deferred work" |
| Embedding retrieval | already gated | Package 7B, needs a held-out review first |
| Cache-read token accounting | **adopt — S9 above** | the only real gap found |

## Order and dependencies

```
S1 ─┬─▶ S3 ──▶ S5 ──▶ S4 ──▶ S7
S2 ─┘             │
S6 (parallel) ────┘
```

- S1 and S2 are prompt/research work and can start together; S1 consumes S2's knowledge file.
- S3 depends on S1 (final prompt shape) and on the verified model names (already in this spec).
- S5 depends on S3 (routing config) and on S6's cost model for the cascade ceiling.
- S4 depends on S1 (consultation agent prompt) and S3 (provider route), and wires the existing
  Package 6 campaign decision points.
- S7 depends on S3/S5 (the final prompts and routes the fixtures must cover).
- S6 runs in parallel from day one.
- S8 is on-command and depends on S4 (the consultation lane it instruments) and S6 (its tool steps
  are billed); it changes no shipped lane until the owner commands it.
- S9 is independent: it corrects the settlement path S6 shipped and can land any time.

## Owner sign-off flags (answer before work starts)

1. **Default-on** (S3) reverses the 3H fail-closed default. Confirm the language lane ships enabled,
   with `campaign-consultation` still `off` until S4 is measured.
2. **"MoE"** is interpreted as per-task model routing + a bounded escalation cascade (S5), not a new
   SDK feature. Confirm that reading.
3. **Model names** are pinned from the 2026 vendor docs: `deepseek-flash` + `deepseek-v4-pro`
   (thinking) + `gpt-5.6-luna`. Confirm before S3/S5 land; a vendor rename only touches
   `config/routing.php` and the two agent `providerOptions`.
4. **Prompt knowledge (S2)** must never contain object ids / machine names / prices — it is authored
   reasoning, host data stays in the request context. Confirm the gate-1 test as the acceptance bar.

## Explicitly out of scope

- SDK tools, MCP, web/file search, attachments, sub-agents, agent memory, SDK queue/broadcast
  lifecycle, streaming, embeddings/reranking (still gated by their own experiments).
- AI-to-AI LLM chat, periodic reflection, per-event summaries, gameplay batch planning, a local model
  runtime, or any host-side combat/dispatch authority.
- No second provider client or advice store: everything reuses `LanguageGateway`,
  `CampaignConsultationGateway`, the receipt ledger and the operator controls that already exist.
