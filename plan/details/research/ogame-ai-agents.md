# OGame bots and AI agents — second discovery pass

Research note, 17 September 2026. Extends
[ogame-automation-algorithms.md](ogame-automation-algorithms.md) (sixteen projects, P1–P13, source-verified)
with a **discovery pass** over the GitHub `ogame` / `ogame-bot` topics and web search. Confidence is
stated per project: this pass read **READMEs and topic metadata**, not source, except where a file is
named. Read it for what to adopt and what to refuse, never as a licence to port an algorithm.

The headline finding: two projects implement exactly the two halves of our own design, independently —
an **LLM agent** for OGame, and **AI players inside the OGameX engine itself** — which both confirms the
architecture and shows where the wild implementations drift from the three gates.

## The projects

| Project | Stack | What it is | Worth adopting |
| --- | --- | --- | --- |
| [`Shinigallo/ogame-agi`](https://github.com/Shinigallo/ogame-agi) | Python, Gemini 2.0, Playwright | An **LLM agent** ("strategic RAG system") with two modes: *Full Autonomous* (AI every ~5 min) and *Smart* (event-driven, AI ~every 30 min, "90% less tokens") | The event-driven + rule-fallback split |
| [`hammermaps/OGameX-AI-Players`](https://github.com/hammermaps/OGameX-AI-Players) | Laravel 13 (a fork of `lanedirt/OGameX`) | **AI players in the OGameX engine**: 6 strategy profiles (Miner, Raider, Neutral, Aggressive, Defensive, Turtle), a background daemon, `/admin/ai-players` UI | A same-engine comparison baseline |
| [`janeczkins/xbot`](https://github.com/janeczkins/xbot) | .NET 9, closed-source binaries; engine = `alaingilbert/ogame` | Mature automation bot; README documents the feature set in detail | Three named mechanisms, below |
| [`eracle/ogamebot`](https://github.com/eracle/ogamebot) | Java + Selenium, 11 y old | Aspirational "AI that defeats humans, behaves human, coordinates via alliances"; a stub (login + read resources) | The goal statement only |
| [`alaingilbert/ogame`](https://github.com/alaingilbert/ogame) | Go | The canonical Go OGame API wrapper (`xbot`'s engine) | Reference API surface only |
| [`patrykstefanski/og-battle-engine`](https://github.com/patrykstefanski/og-battle-engine) | PHP/Python | A second battle-engine implementation for OGame and clones | Nothing — it is the duplication our contract forbids |
| [`ogame-opensource-2` `wiki/en/ai.md`](https://github.com/AntonBeletskyForks/ogame-opensource-2/blob/master/wiki/en/ai.md) | PHP clone | The built-in "AI" of an OGame clone: bots evolve to small cargo, then hibernate | The floor for what "AI" means in the wild |
| Gameforge forum [Third Party Tools & Responsible AI Use](https://forum.origin.ogame.gameforge.com/forum/thread/500-third-party-tools-responsible-ai-use/) | — | Official policy: credit open-source baselines, botting violates ToS | The guardrail, not code |

## What to adopt — three things

### 1. The event-driven + rule-fallback split (ogame-agi, README)

`ogame-agi`'s *Smart* mode is, independently arrived at, our own architecture:

```
quick check every 60 s  ->  no AI, rule-based only
AI decision only when   ->  building/research completed · resources over threshold · fleet returned · emergency
fallback action         ->  if metal > 50000: build the metal mine   (authored rule, no model)
```

This is a third-party confirmation of the module's standing rule — *ordinary gameplay, native memory,
CBR and AI-to-AI exchanges make zero generative calls; the model is asked only at a material,
unresolved decision* (`plan/details/specs/budgets.md`). It also matches the shape of S8's tool set:
the model pulls facts only when it is actually weighing a decision, never on a timer. **Adopt the
confirmation, not the code** — its thresholds (`metal > 50000`), object names (`metal_mine`) and the
"3:2:1" ratio are authored constants in the prompt, which is the gate-1 failure our prompts are tested
against (`PromptGateOneTest`).

### 2. Three named mechanisms from xbot (README)

xbot's feature list restates most of P1–P13 and adds three concrete, gate-3-nameable behaviours the
module does not yet model:

| Mechanism | What it does | Maps to |
| --- | --- | --- |
| **AutoRepatriate** | periodically move resources to a single "drop" celestial | the deferred **X1 transfer executor** (`DEF-002`); the trigger + "one drop planet" policy is the missing half |
| **BuyOfferOfTheDay** | buy the daily Trader item automatically | an ordinary experienced-player habit we do not model; a candidate future slice |
| **AutoFleetJumpGate** | route transfers through the jump gate | the same X1 surface, gated on a moon + jump gate the accounts must first build |

All three are host-read and host-executed in our world — no object list, no threshold constant, no
scraping. They are *candidate* slices, not this pass's work.

### 3. The same-engine comparison (OGameX-AI-Players, README + `agent.md`)

A fork of the engine we build on ships **AI players with six strategy profiles, a background daemon and
an admin panel** — i.e. it put AI *inside the host*. That is precisely what our module rule forbids
("do not move AI policy, persistence, or orchestration into the host"). The fork is MIT and same-engine,
so its `agent.md` and AI-player controller are worth one **source pass** as a comparison: profile
taxonomy (their Miner/Raider/Neutral/Aggressive/Defensive/Turtle vs our Miner/Turtle/Fleeter/Trader/Casual),
how their daemon schedules, and how they score — to find gaps, never to port.

## What to refuse, restated

- **Client automation.** Selenium/Playwright login, captcha solving, proxies, cookies, anti-detection
  timing (`eracle/ogamebot`, `xbot`, `ogame-agi`'s browser layer). The module runs inside its own
  universe through host services; none of the client-side operation code is in scope, and OGame's rules
  forbid it — which is also why the module must never automate an official account.
- **A second battle engine.** `og-battle-engine` duplicates the host's own formulas — the exact
  duplication the module contract forbids.
- **Hardcoded strategy in prompts.** `ogame-agi`'s knowledge base names objects, ratios and thresholds
  in the prompt. We keep those host-read and context-supplied.
- **A host-side AI daemon.** `OGameX-AI-Players` is the negative example for the module boundary even
  as it is the positive example for persona variety.

## Confidence and follow-up

README/metadata level, except `agent.md` noted above. The one follow-up worth a task is a **source pass
on `hammermaps/OGameX-AI-Players`** (agent.md + the AI-player controller) to record its profile
taxonomy, scheduling and scoring against ours; everything else here is a confirmation or a refusal, not
new code.

---

## Source pass — `hammermaps/OGameX-AI-Players` (17 September 2026, DISC-007)

Read the actual source, not the README. The fork ships AI players **inside the host**: `AiPlayer` /
`AiPlayerLog` / `AiDaemonMetric` models, `app/Services/AiPlayer/*` services, an `ogamex:ai:daemon`
command, admin controllers and `/admin/ai-players` views, plus a `User.is_ai_player` flag. That is the
module boundary our rule forbids — the fork is the positive example for persona variety and the
negative example for placement.

### Profile taxonomy — theirs vs ours

| | Them (`AiPlayerProfile`) | Us (`AiArchetype` + `AiSkillBand`) |
| --- | --- | --- |
| Set | 6: Miner, Raider, Neutral, Aggressive, Defensive, Turtle | 5: Miner, Turtle, Fleeter, Trader, Casual |
| What a profile **is** | a hardcoded strategy class with machine-name priority lists (`getBuildingPriorityList()` → `metal_mine`, `crystal_mine`, …; `decideUnitBuild()` → `rocket_launcher => 20`) | a policy that weighs host-read features into a utility score |
| Taste | `getDefaultPriorities()` → `{building, research, fleet}` ints 1–10; preferred `CharacterClass` (Miner/Turtle → Collector, Neutral → Discoverer, rest → General) | `ArchetypePolicy` feature weights + `selectionMargin()` |

Their profile is the **gate-1 failure our prompts are tested against** — every building, ship and
threshold is a literal in a strategy class (`cruiser => 5`, `light_fighter >= 5`, `planetCount() < 7`).
Adding a host object changes nothing for them. Our archetypes say *how much* to value a host-read
candidate, never *which object*. The one taste-level idea worth keeping as a mapping, not a port: their
`getDefaultPriorities()` is a compact "what this persona spends its turns on", which our archetype
weights already express.

### Scheduling — theirs vs ours

- **Them:** one daemon loop (`ogamex:ai:daemon --interval=30`) per player row: `action_interval_min/max`
  random gap, `sleep_start/sleep_end` window, `next_action_at`, and a "human variance" 5 % idle-skip
  when `difficulty_level < 4`.
- **Us:** per-account `AiSchedule` (timezone, `next_due_at`, generation) + `RoutineProfile` awake/sleep
  window + SP3 material-event wake + V2 reaction wake + right-skewed arrival delay.

Same three ideas, independent: a sleep window, a jittered gap, and an occasional "did nothing" pass.
Ours are per-account and event-driven; theirs are one daemon clock. Their 5 % idle-skip is a nice
confirmation that a *fail-to-act* pass is part of ordinary play — which our right-skewed arrival delay
and "a save that can fail" already cover.

### Decision — theirs vs ours

- **Them:** no utility score. A turn runs `rand(1, 10) > priority_*` gates to pick which subsystem acts,
  then a strategy's priority list picks the next object. Target selection is `rand()` over a nearby
  system/position.
- **Us:** `UtilityScorer` over scored candidates (resource need, safety, target confidence, travel cost,
  recovery) → select; target reports scored, never random.

Their loop is smaller and simpler (gate 2); our scorer is the gate-3 answer (a player weighs the
features, not a die roll). No adoption either way — the scorer is the one place we deliberately have
more machinery, because gate 3 demands it.

### The interesting extra — bot-detection signals on their own AIs

`AiDaemonMetric` and the server-administration view compute authenticity flags over their own players:
`round_the_clock` (active ~24 h/day) and `instant_expedition` (mission returned with no plausible travel
time), shown as warning triangles on each AI account. That is their own cheat-detector aimed at their own
bots, and it names two concrete things our accounts must not do — which our authenticity signals already
measure (uptime shape, reaction latency, save quality). Worth recording: the fork *knows* its players
read as bots on a 24 h uptime and instant-returning fleets, and we already model both.

### Verdict

Confirmations: sleep window + jitter + occasional no-op pass; per-profile turn priorities; the
`round_the_clock`/`instant_expedition` flags as two authenticity ceilings. Refusals, restated: hardcoded
machine-name build lists and thresholds (gate 1), host-side daemon + admin UI + `is_ai_player` (module
boundary), `rand()` targeting (not gate-3 play). No code comes across.
