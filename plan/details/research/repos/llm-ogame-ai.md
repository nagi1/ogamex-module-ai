# LLM-using OGame AI/bot projects — research notes

Scope: OGame AI/bot projects that **explicitly** call an LLM (OpenAI-compatible, DeepSeek, Gemini,
Claude, local models). Generic game-agent LLM frameworks (Voyager-style, generative agents) are
included only for what they teach about prompting and context management. Projects that *claim* LLM
usage but are actually rule-based are flagged explicitly.

Search method (2026-09-17): GitHub repository search + GitHub search API
(`api.github.com/search/repositories`) for `ogame` × {`llm`, `openai`, `gpt`, `gemini`, `deepseek`,
`claude`, `llama`, `chatgpt`, `langchain`, `ai agent`}, plus `ogamex` × {`llm`, `agent`}.

The OGame LLM-bot field is tiny. Exactly **three** repos genuinely call an LLM; the rest are
rule-based bots that borrowed AI-sounding names, or host clones. Details below.

---

## 1. `Shinigallo/ogame-agi` — Gemini brain + fake "strategic RAG"

- URL: https://github.com/Shinigallo/ogame-agi
- Language: Python (Playwright + Gemini). 0 stars, 6-month-old, MIT.
- **Models:** `gemini-1.5-flash` (`GeminiBrain`), `gemini-2.0-flash-exp` (`EnhancedGeminiBrain`).
- Full per-mechanism analysis already exists in this directory: `shinigallo-ogame-agi.md`.
  Key LLM facts, extracted there:
  - **Prompting:** system-style prompt that inlines a knowledge base string + resource state +
    strategy + risk tolerance + 6 numbered "DECISION RULES"; output contract is a JSON array of
    `{type, target, priority, reason}` / `{type:"fleet_dispatch", mission, target, ships, priority, reason}`.
    `_parse_decisions` strips ``` fences then `json.loads`; `_validate_decision` requires `type` +
    `priority` and an allow-list of types `['build','research','fleet_dispatch','wait']`.
  - **Context management:** "strategic RAG" is **not** RAG — `sentence_transformers`/numpy are
    commented out; retrieval is keyword substring/tag scoring with category priorities
    (`fleetsaving:10, combat:9, …`), top-5 results, content truncated to `[:200]`, prompt `[:300]`.
  - **Delegation split:** LLM chooses among a fixed 4-action vocabulary + priorities; the game
    interaction (login, resource parse, timers, dispatch) is Playwright/DOM code. Fallback is
    deterministic (`metal<1000 → metal_mine`, else `wait`).
  - **Cost/robustness:** no caching; broad `try/except` per cycle → deterministic fallback on any
    AI failure; `max_tokens` absent from the snippet but confidence computed client-side (base 0.5,
    +0.2/0.1 for retrieved strategy counts, cap 1.0).
  - **Verdict:** genuine Gemini calls, but broken seams (`RAGSystem` import, missing methods),
    fabricated "rankings", hardcoded object IDs/queues (gate-1/2/3 violations), and a keyword-search
    mislabeled as RAG. Leaks a real Gemini API key in source.

---

## 2. `TheOnlyBeardedBeast/ogame-agent` (DotAgent) — Semantic Kernel commander with self-reflection

- URL: https://github.com/TheOnlyBeardedBeast/ogame-agent
- Language: C# (.NET 9 gRPC backend) + Flutter client. 0 stars.
- **Models:** OpenAI-compatible endpoint (default `https://api.groq.com/openai/v1`); two named
  Semantic Kernel chat-completion services — `moonshot` = `moonshotai/kimi-k2-instruct` (game cycle +
  strategy), `llama` = `meta-llama-3.1-8b-instruct` (interactive chat). `GAME_MODEL` / `CHAT_MODEL` /
  `LLM_ENDPOINT` / `API_KEY` env-configured.
- **How it prompts:** Semantic Kernel agents with per-service names; the game cycle runs five
  streamed phases (resource status → building status → research status → building action selection →
  research action selection), and **all phase chunks are appended into a single cycle transcript**.
- **Context management — the notable idea:** a two-step self-reflection loop:
  1. After the five phases, the model **summarizes** the cycle into `LastActionSummary` (memory) and
     appends a line to `data/cycle_summaries.log` (compact historical trace).
  2. The model then generates **one concise next-run suggestion** grounded in the transcript + current
     goal, saved as `NextRunSuggestion` and **injected as system context at the start of the next
     cycle**. Empty suggestion → safe fallback.
  This is a cheap persistent-memory pattern: full transcript never carried forward, only a
  summary + a one-line directive.
- **Delegation split:** LLM picks building/research actions; Playwright + JS scrapers read game
  state and execute. A "pre-check" skips the decision call entirely if a build/research is already
  in progress (only schedules the next run) — a **skip-on-trivial-turn cost trick**.
- **Cost/robustness:** no caching mentioned; TickerQ schedules next cycle; tool methods
  lock-protected against concurrent collisions; goal handoff (`FutureGoal` → `MainGoal`).
- **Verdict:** the cleanest prompt/context pattern of the three — transcript → summary → next-run
  suggestion as rolling short-term memory, and a cheap-branch rule that avoids LLM calls when a job
  is already queued.

---

## 3. `kmlwlkwk/aem-ogame` (OGame Commander "Camillo") — periodic strategist + one-shot director

- URL: https://github.com/kmlwlkwk/aem-ogame
- Language: Node.js + Playwright + blessed TUI + SQLite. 1 star, MIT.
- **Models:** OpenAI-compatible API; tested with **OVHcloud AI Endpoints —
  `Qwen2.5-VL-72B-Instruct`**; `OPENAI_MODEL` must support **JSON mode** (`response_format: {type:"json_object"}`).
- **Two distinct LLM roles** (`src/ai/`):
  - `strategist.js` — **periodic** multi-planet strategic plan, refreshed every
    `AI_REFRESH_CYCLES` (default 3 cycles ≈ 15 min), not every cycle.
  - `director.js` — **one-shot** interpretation of a natural-language player directive into a
    tactic + params + destructive-risk flag.
- **Prompting (strategist):** long system prompt ("expert OGame strategist…") with hardcoded tech-ID
  semantics (1=Metal Mine, 2=Crystal Mine, … 15=Shipyard, 31=Lunar Base) and hard rules (Solar Plant
  only on energy deficit; research recommended once because it is global; transports only when source
  has enough and target needs it; Large Cargo id=203 carries 25000; recommend NEXT build when queue
  busy). Output is a strict JSON schema: `planetActions[{coords,buildNext,buildId,reason,urgent}]`,
  `transports[{from,to,metal,crystal,deuterium,reason}]`, `researchNext`, `advice` (≤80 words),
  `confidence` (0.0–1.0). `temperature: 0.2`, `max_tokens: 700`.
- **Prompting (director):** system prompt "advisory only — you must NOT substitute a different
  action"; JSON out `{tactic∈{economics,defense,attacker,collector}, params{preferNearby,
  aggressiveness∈{conservative,normal,aggressive}, buildTarget, notes}, explanation≤60w,
  destructive, destructiveReason}`. `temperature: 0.3`, `max_tokens: 300`.
- **Context management:** strategist sends a **summarized snapshot** per planet
  (`summariseSnapshot`: coords, name, isMoon, resources, energy, buildings, fleet, defense) + a
  growth-trend object, not raw DOM. Director sends an even leaner aggregate (planet count,
  `totalFleetValue` rough metal-equivalent, fleet/defense **totals only**) — "enough context without
  sending full planet data". Deterministic `defaultPlan`/`defaultInterpretation` fallbacks on error.
- **Safety gate (the notable idea):** the LLM only **classifies** whether the directive is
  destructive ("attacking 10× stronger defense"); execution is refused unless the player prefixes
  `/force`. The LLM cannot substitute an action for the player's command — a hard authority bound.
- **Cost-control tricks:** periodic refresh (not per-cycle), token-efficient caching mentioned in
  README, lean aggregated context, `max_tokens` caps (700/300), JSON mode for deterministic parsing,
  AI disabled entirely when `OPENAI_API_KEY` is unset (falls to deterministic defaults).
- **Verdict:** the best cost-control + authority-splitting example: LLM does *planning* and
  *classification*, never execution; deterministic tactics (`economics/defense/attacker/collector`)
  do the actual play.

---

## False positives — claim LLM/AI but are rule-based (or not bots)

| Repo | URL | Why it is NOT an LLM bot |
|---|---|---|
| `seba0456/Ogame-Gemini-Tools` | https://github.com/seba0456/Ogame-Gemini-Tools | "Gemini" is a **bot module name** (expedition dispatch), predating Google's Gemini; the sibling "Voyager" module is a universe scanner. Pure Python rule-based automation, no model call. Archived 2023. |
| `BenJonesVA/OGameX-Claude` | https://github.com/BenJonesVA/OGameX-Claude | "Claude" = Claude *wrote the framework*; it is an OGame redesign clone in Laravel (a host), not an LLM bot. |
| `tommyz7/ogame-ai-react` | https://github.com/tommyz7/ogame-ai-react | 2018 UI shell for a bot; pre-LLM, no model call. |

## Related but out of scope (host/API surface for AI agents, not agents)

- `Asurelia/ogamex-next` — https://github.com/Asurelia/ogamex-next — "OGameX rebuilt … with API for
  AI agents" (Next.js + Supabase). This is the host our module targets.
- `jessecrouch/ogamex-go` — https://github.com/jessecrouch/ogamex-go — "API-only version of OGame
  designed for AI agents to play" (Go). Same host-infrastructure role.
- `Rivenscryr/origin-tooldev-agents` — https://github.com/Rivenscryr/origin-tooldev-agents —
  `AGENTS.md` guardrails for AI *coding* agents within OGame tool rules (not a gameplay agent).

---

## Generic game-agent LLM frameworks (transferable lessons only)

### `MineDojo/Voyager` — https://github.com/MineDojo/Voyager (7.2k★)
- **Model:** GPT-4 via blackbox API queries (no fine-tuning).
- **Prompting/loop:** three components — (1) automatic curriculum (propose next task from prior
  successes); (2) **skill library** — executable code skills stored in a vector DB (chromadb),
  retrieved by embedding similarity and composed; (3) **iterative prompting** — generate code, run it
  in the environment, feed **execution errors + environment feedback back into the prompt**, plus a
  critic agent for **self-verification**, up to a fixed refinement budget.
- **Lesson for OGame:** treat an OGame action as a *named, reusable skill with a verifiable effect*
  (did the build actually queue? did the fleet return?), and let failed execution feed back into the
  next prompt rather than retrying blind. Our `app/Domain/Decision/CandidateActionFactory` already
  mirrors the "skill registry" idea.

### `joonspk-research/generative_agents` (Smallville) — https://github.com/joonspk-research/generative_agents (22.1k★)
- **Model:** ChatGPT (gpt-3.5-turbo) for most modules; GPT-4 for some.
- **Context management — the canonical memory pattern:** a **memory stream** of timestamped
  observations, retrieved by a scoring function that multiplies **recency (exponential decay) ×
  importance (model-rated at write time) × relevance (embedding cosine similarity)**. On top:
  **reflection** periodically synthesizes higher-level inferences ("Klaus is absorbed in his
  research"), and **planning** produces a coarse plan recursively decomposed into concrete actions
  (plan ↔ action tree), with a start-of-day summary.
- **Lesson for OGame:** the AI module's agent-memory research
  (`plan/details/research/agent-memory-tooling.md`) already prescribes exactly this — decay from
  last access, write-time importance, weighted retrieval, selective forgetting. Smallville is the
  reference implementation, not a new idea; the README notes running many agents is *costly*.

### `BAAI-Agents/Cradle` — https://github.com/BAAI-Agents/Cradle (2.6k★)
- **Model:** GPT-4o and Claude (config `openai_config.json` / `claude_config.json`).
- **Interface:** screenshots in, keyboard/mouse out (general computer control; tested on RDR2,
  Stardew Valley, Cities: Skylines). Prompt templates per game under
  `res/<game>/prompts/templates/{action_planning, information_gathering, self_reflection,
  task_inference}.prompt`; a **skill registry** of atomic → composite skills; memory module.
- **Lesson for OGame:** the *four-prompt decomposition* (gather info → infer task → plan action →
  reflect) is a reusable shape for a browser bot, but for us the module has a typed host API and
  should not go screenshot-based. Its per-game prompt-template layout is the pattern our
  `strategy-mining` gate already rejects when it hardcodes game facts — keep prompts gate-1 safe
  (host-read only).

---

## Learnings

### Prompting patterns
1. **Strict JSON output contract** is universal: all three OGame bots force a fixed schema
   (`response_format: json_object` / `json.loads` + field validation), and validate against an
   **allow-list** of actions (`build/research/fleet_dispatch/wait`;
   `economics/defense/attacker/collector`). The LLM chooses from a closed vocabulary — it never
   invents new actions. (shinigallo, camillo)
2. **Rules in the system prompt, not in code**: numbered "DECISION RULES" and "KEY RULES" encode
   *taste* (production first, 3:2:1 ratio, fleetsave-if-enabled, no Solar Plant unless deficit,
   research-once-because-global). These are the gate-3 "what a player does" knobs. (shinigallo,
   camillo)
3. **Separate the planning call from the one-shot call**: a periodic strategist (every N cycles) for
   the big picture + a cheap one-shot director for interpreting a single directive. Do not make the
   expensive model re-plan every tick. (camillo)
4. **Advisory-only + destructive flag**: the model classifies risk and explains; a deterministic
   gate (and human `/force`) decides whether it executes. The LLM cannot substitute a different
   action. This is the cleanest authority bound seen. (camillo)
5. **Self-reflection as a two-call loop**: transcript → summary → "next-run suggestion" that becomes
   next cycle's system context. (dotagent) Echoes Voyager's critic/self-verification and Cradle's
   self_reflection template.

### Context-management patterns
6. **Summarized/aggregated snapshots, never raw state**: per-planet summary dict (resources,
   buildings, fleet, defense) + growth trend (camillo); fleet/defense *totals* for the director.
   Voyager/Smallville confirm: feed the model a distilled state, keep raw state in code/DB.
7. **Rolling short-term memory via summary + one-line directive** — full transcripts are never
   carried forward (dotagent). Same idea as Smallville's reflection synthesizing higher-level facts.
8. **Retrieval ≠ embeddings**: shinigallo's "strategic RAG" is keyword scoring mislabeled as RAG; the
   real embedding-retrieval pattern is Voyager's skill library and Smallville's memory stream. Our
   module already has the right spec in `agent-memory-tooling.md`; do not copy the fake one.
9. **Truncation budgets** (top-5 results, `[:200]` content, `[:300]` prompt, ≤80-word advice) are the
   only explicit window-management the OGame bots do; none does real summarization-on-overflow.

### Model choices
10. No OGame bot uses OpenAI/DeepSeek/Claude *natively*; they use **OpenAI-compatible endpoints**
    pointed at cheap models: Groq (`kimi-k2-instruct` + `llama-3.1-8b`), OVHcloud
    (`Qwen2.5-VL-72B-Instruct`), Google Gemini (`1.5-flash`/`2.0-flash-exp`). Pattern: **small/fast
    model per decision, tiered by task** (dotagent splits game-cycle vs chat; camillo splits
    strategist vs director).
11. Generic frameworks standardize on frontier models (GPT-4 / GPT-4o / Claude), which is the exact
    cost profile our 2 vCPU/2 GB reference deployment must avoid.

### Cost control
12. **Skip the call on trivial/no-op turns**: dotagent skips the model when a build/research is
    already in progress; camillo disables AI entirely when no key and refreshes only every N cycles.
13. **`max_tokens` caps + JSON mode** (700 strategist / 300 director) bound both output cost and
    parse risk. (camillo)
14. **Caching the plan across cycles** ("token-efficient caching", `AI_REFRESH_CYCLES`) — plan once,
    execute deterministically several times. (camillo)
15. **Deterministic fallback on every failure path** — defaultPlan / defaultInterpretation /
    `_get_fallback_decisions` mean an LLM error never stalls the account (all three repos). This
    matches our fail-closed + native-fallback doctrine.
16. **Tiered/two-model routing** (big model for strategy, small model for chat) is the only
    tiering seen; no repo does prompt-cache keys, batched/deferred model batches, or refusal-on-trivial
    explicitly beyond the skip-in-progress rule. Deferred batching remains our own (undone) idea.
