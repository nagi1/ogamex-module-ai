# Laravel AI tools — study for the LLM and PvE packages

Research note, 17 September 2026. This reads the vendored `laravel/ai` `^0.11` source (`vendor/laravel/ai/src`)
to say what the **tools** feature is, how it works, what the module forbids today and why, and how the
module can use it to stop stuffing facts into prompts. Read with
[laravel-ai-sdk.md](../specs/laravel-ai-sdk.md) and [llm-full-utilisation.md](../specs/llm-full-utilisation.md).

## What the feature is

A tool is a typed, app-side function the model may call during a run. The model sees each tool's
**name, description and JSON-argument schema**, decides whether to call one, and the SDK executes it and
feeds the string result back into the conversation for the next step — the model never gets a tool's
implementation, only its contract and its answer.

The contracts (all under `Laravel\Ai\Contracts`):

| Piece | Signature | Role |
| --- | --- | --- |
| `Tool` | `description(): Stringable\|string`, `schema(JsonSchema): array`, `handle(Request): Stringable\|string` | One named capability. `description` and `schema` are what the model sees; `handle` runs in PHP. |
| `HasTools` | `tools(): iterable` (list of `Agent\|Tool\|ProviderTool`) | An agent implements this to expose tools. |
| `Request` | `string('key')`, `int('key')`, `all()`, `validate(rules)`, ArrayAccess | The typed arguments the model supplied; the `toolCallId` is an external idempotency key. |

Mechanics (`Providers/Concerns/GeneratesText.php`, `Gateway/Concerns/InvokesTools.php`,
`Gateway/TextGenerationLoop.php`):

1. An agent that implements `HasTools` returns its tool list; each bare `Agent` is wrapped in
   `AgentTool` (a sub-agent delegation), and `Tool` instances pass through.
2. The gateway serializes the tool schemas into the provider request (`MapsChatCompletionTools` for
   the OpenAI-compatible path DeepSeek uses; DeepSeek's published API supports tool calls).
3. When the model answers with `tool_calls`, `InvokesTools::executeTool()` runs each tool's `handle()`
   with a `Request`; a `ValidationException` from `validate()` is returned to the model so it can
   correct its arguments, any other throwable fails the run.
4. The string result is appended as a `ToolResultMessage` and the loop steps again, up to `maxSteps`
   (default 25). So one foreground prompt can become one prompt + N tool calls + one final answer.

Two things worth stating because they contradict casual assumptions:

- **`#[Strict]` does not disable tools.** It only sets strict JSON-schema `response_format` for the
  structured output envelope. The module's agents are `#[Strict]` today and could still carry tools.
- **Tool output is returned as a string into the conversation**, not typed back into the schema. The
  final structured envelope is still the allowlisted schema the module already validates.

## What the module forbids today, and why

`laravel-ai-sdk.md` says "Do not expose SDK tools … the reply agent implements no tool capability", and
Package 6A's acceptance says the consultation agent "cannot … use a tool, access memory, invoke a
sub-agent or execute host work". Those are **authority boundaries**, not preferences: the model may
propose and phrase, never look up unbounded state, never mutate, never become an alternate source of
truth. A tool that simply re-opened that door would be wrong for the same reasons the boundary exists.

The boundary's cost is the prompt tax: `BuildCampaignConsultationBriefAction` and
`GenerateAiReplyAction` serialize *every* fact the model might need — candidates, scores, evidence,
messages, persona, constraints — into one bounded prompt, and the token budget trims what does not fit.
Tools are the standard fix for exactly that: **let the model pull the specific facts it needs, on
demand, through a narrow typed window, instead of pre-shipping all of them.**

## Design rules for module tools (the gates applied)

A tool that survives the three gates has these properties:

- **Gate 1 — host-read, never a source of truth.** A tool's `description()` names a capability in
  words, never an object id, machine name, price or requirement. Its `handle()` reads the host
  catalogue and the module tables at call time. Adding a host object changes what a tool returns with
  no module edit.
- **Gate 2 — one class, one bounded query.** No `Tool` that is a thin wrapper over an action that
  already exists, no tool registry or manager, no config for a constant. A tool is read-only, its
  arguments are a JSON allowlist, and its result is bounded (a limit, a timeout, and a stable shape).
- **Gate 3 — the answer must be nameable as what a player can see.** A tool returns facts a player
  could read off the game or the module's own records — campaign state, a counterparty's remembered
  terms, the legal candidates and their native scores — never hidden state.
- **The module stays the authority.** A tool returns **evidence**; the deterministic validator still
  re-checks every value the model proposes (candidate legality, source attribution, current validity).
  A tool must never grant what the native policy would refuse, and never mutate.
- **No injection surface.** Tool arguments are untrusted data, validated before use; tool output is
  data the model reads, never instructions the module executes. A tool must not echo hidden reasoning
  or another account's private fact.

## Proposed tool set (for the plan, not yet built)

Each is a small `Tool` class the agent exposes through `HasTools`:

| Tool | Answers | Replaces what is in the prompt today |
| --- | --- | --- |
| `CampaignFactsTool` | open/completed objectives, phase, deadline, per-campaign counters | the `campaign` block of the serialized brief |
| `LegalCandidatesTool` | the candidate list with native scores and reasons, re-read for current legality | the `candidates` block of the brief |
| `CounterpartyFactsTool` | the module's current, non-stale memory facts for one counterparty | the recalled-memory section of the reply context |
| `HostCapabilityTool` | the host's price/requirements for a candidate the model is weighing | any pre-serialized price/requirement table |

The reply agent keeps its no-tools posture for wording; the consultation agent (and later the PvE
director) is where tools pay, because those are the lanes that must weigh a decision.

## What deliberately stays out

- SDK provider tools, MCP, web/file search, sub-agent delegation (`AgentTool`), and human-tool
  approval — these grant authority or reach the model was never given.
- Any tool that mutates state, schedules work, or resolves a decision. Tools are read-only.
- A tool per fact. The set above is four; a fifth tool with no consumer is a gate-2 failure.

## Feasibility notes

- DeepSeek (`deepseek-flash`, `deepseek-v4-pro`) documents tool calls as supported; the SDK's DeepSeek
  gateway already maps them (`MapsChatCompletionTools`). OpenAI's `gpt-5.6-luna` supports them too.
- Cost: every tool call is one more step in the same foreground request, so its input/output tokens
  are billed like any other step. The win is **prompt-side**: the base prompt shrinks to instructions
  + the current turn, and only the facts the model actually asks for are read. The S6 cost model must
  count tool steps, not assume a single completion.
- Tests: tools are exercised with the same `Http::fake` fixture replay as S7, plus the SDK agent fake
  for the tool-call loop — and the existing `preventStrayPrompts` discipline.
