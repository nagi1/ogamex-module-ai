# Shinigallo/ogame-agi → the north star: an integration plan

Written 10 October 2026. Owner request: *how can we use this repo, with its data and tools, to enhance
the AI module towards the north star?* This file answers that against the north-star gate and the three
cognition gates, and replaces the older per-repo plan (`shinigallo-ogame-agi.plan.md`) as the decision
record; it **builds on**, and does not re-derive, the source analysis in
`../shinigallo-ogame-agi.md` and the mined-idea residue in `../../../specs/reopened-repo-value.md`.

Read the north star first: *the accounts must play like experienced human OGame players, and a change
counts only when the cohorts show it* — an `aspect:` of `bash scripts/ogamex scorecard` moves, a
`situation:` passes, or an `invariant:` stops firing (`AGENTS.md`, `plan/HANDOFF.md`).

---

## 0. Verdict (one line)

**REFUSE-shaped. This repo contributes no code and no tool the module may use, and its only admissible
data is a tertiary strategy digest worth at most one bounded read — and only where an aspect is failing
and our corpus has no number.** Nine of the fifteen scored aspects it cannot touch at all; the three
gates are violated by its *central mechanism*, not by an edge case. Net recommendation: **spend nothing
on code; at most one research note (U1), otherwise close the repo.**

---

## 1. Why the tools are out of scope (not a judgement on quality)

The module plays from *inside* the host: it perceives, decides, schedules and calls ordinary OGameX
actions; it never drives a client. `ogame-agi` is the opposite construction — a Playwright/Chromium bot
that scrapes the live Gameforge DOM and asks Gemini for a JSON action list on essentially every
decision. Its three load-bearing pieces are exactly the three things the module forbids:

| Repo piece | What it is | Forbidden by |
| --- | --- | --- |
| `src/automation/*`, stealth flags, selectors | browser login, DOM resource parsing, hardcoded building/tech/universe IDs | module boundary (no browser session) + **gate 1** (object names/ids as truth) |
| `src/agents/*` (Gemini every cycle) | a generative call on the ordinary decision path | **budgets**: deterministic play, zero generative calls in gameplay |
| `docs/strategic_knowledge_base.md`, `_get_basic_knowledge()` | object lists, ratios and thresholds hardcoded as prompt truth (`metal_mine`, `3:2:1`, `metal>50000 → metal_mine`) | **gate 1** (a capability must not depend on a list we keep) + **gate 3** (a human has no such fixed list) |

So the "tools" half of the question resolves to a refusal. The "data" half is thin, and treated below.

---

## 2. The north-star test, applied

The gate asks one question per change: *which aspect moves, and what would an observer see the account
do?* Applied to everything this repo offers:

| Scorecard aspect | What the repo offers | Verdict | Would-be proof |
| --- | --- | --- | --- |
| `economy` | resource-full thresholds + a `metal>50000 → metal_mine` spend list | **REFUSE** — gate 1 (hardcoded threshold) + gate 3 (no human fires that list) | — |
| `research` | a fixed research queue (`Espionage→…→Combustion`) | **REFUSE** — gate 1 (object order as truth) | — |
| `shipyard` | a fixed build queue (`MetalMine 10, …`) | **REFUSE** — gate 1 | — |
| `espionage` | nothing | — | — |
| `raids` | a hardcoded `0.7` win-probability threshold; else the "aggressive/dominance" scripts | **REFUSE** — gate 1; and our raid gap is a *doctrine-class* problem (play-coverage), not a threshold | — |
| `transport` | folding into the same spend list | **REFUSE** — gate 1 | — |
| `recycle` | the digest's "30 % debris" | **drop** — a host fact (the host owns the debris rate), not doctrine | — |
| `expeditions` | digest numbers: ~200–300 ships, 1 h duration, "256 LC + 1 destroyer" | **candidate pointer only** (U1) — see §3 | `aspect:expeditions` |
| `colonisation` | nothing | — | — |
| `fleet_save` | an `auto_fleetsave` *prompt boolean* ("fleetsave if true") | **REFUSE / already-beaten** — our `QueueableFleetSavePlanner` quotes the host and refuses a destination the save would outlast; the open gap (`FLEET-003` proactive) is a *scheduling* problem this repo does not solve | — |
| `social` / `chat` / `alliance` | nothing — the repo has no social layer at all | — | — |
| `fleet_breadth` | the event-driven "fleet returned → re-dispatch" trigger | **confirmation only** — already shipped (wake-at-next-event); the aspect fails for *missing verbs*, not for expedition size | `aspect:fleet_breadth` |
| `work_failures` | nothing | — | — |

**11 of 15 aspects: no admissible contribution. 1 candidate pointer (`expeditions`). 2 confirmations of
what we already ship.** That is the whole answer.

---

## 3. The only admissible data use — U1, the digest as a *pointer set*

The digest (`docs/strategic_knowledge_base.md` + `src/agents/gemini_brain.py::_get_basic_knowledge`) is
a **tertiary aggregator**: it restates wiki/guide material without citing it, and it contradicts itself
(`2:1:1` mine ratio in the digest vs `3:2:1` in the fallback string). Under the ingest rules a tertiary
source is **ANECDOTAL** and may never set a value — so the correct use is *not* an ingest but a **pointer
extraction**: list its numeric claims, resolve each to a **canonical** source, and keep only the rows
where (a) the claim bears on a failing aspect **and** (b) our corpus has no number or a contested one.

What survives that filter is almost nothing:

| Digest claim | Bears on | Keep? |
| --- | --- | --- |
| mine ratio 2:1:1 (vs 3:2:1) | `economy` | drop — the corpus already holds this as a *contested* doctrine; the repo adds a second unverified opinion, not evidence |
| "6-round combat", "30 % debris" | — | drop — host facts (gate 1: the host owns them) |
| expedition ~200–300 ships, 1 h, "256 LC + 1 destroyer" | `expeditions` / `fleet_breadth` | **the one candidate** — our `resources/behavior/expedition.yaml` encodes only a debris share, no size/duration |
| moon 20 % @ 1.5 M debris | — | drop — host fact |
| building/research priority lists | `economy`/`research` | drop — object lists; admissible only as doctrine data behind a *canonical* source |
| fleetsaving methods (deploy/harvest/colonise/expedition) | `fleet_save` | drop — already in the module |

**Output of U1:** one note at `plan/research/ogame/synthesis/shinigallo-pointer-set.md` — a claim →
canonical-source table, no code, no task row, no behaviour value. Its only job is to *campaign* for a
canonical fetch if the owner ever unfreezes the corpus for a failing aspect that wants that number.

**Cheap, so do it only if the owner wants the differential on record; it is not required to close the
repo.** Running the frozen ingest/doctrine pipeline for this aggregator would violate *behaviour before
sources* and the 1 Oct freeze for no aspect gain.

---

## 4. Behaviour slices this repo would allow — **zero, unless a gate opens**

If a slice were ever justified, its only legal shape is a **doctrine/behaviour YAML value read by an
existing planner** (never a new class; gate 2), proven by an aspect/situation. The one candidate:

- **C1 (conditional).** *If* U1 surfaces an expedition ship-size/duration that
  `resources/behavior/expedition.yaml` does not encode, *and* an expedition-bearing aspect is failing,
  *then* add the value with its **canonical** source id, proven by `situation:ExpeditionSituation`.

It fails its own trigger today: `expeditions` is the cohort's *dominant* verb (it passes), and the
failing neighbour is `fleet_breadth`, which fails because the fleet has **too few verbs**, not because
expeditions are mis-sized. So **C1 is not queueable**; state it here only so the reasoning is on record.

---

## 5. Refuse register (explicit, so it is not re-opened)

- `src/automation/*`, `playwright_interface.py`, stealth flags, `ogame_selectors.py` — browser
  scraping + hardcoded object/universe IDs. Boundary + gate 1.
- `src/agents/gemini_brain.py`, `enhanced_gemini_brain.py` — a model call per decision. Budgets.
- `src/knowledge/rag_system.py` — keyword-only "RAG" (substring scoring, no embeddings). Gate 2; we
  already have structured retrieval + authored knowledge.
- `aggressive_bot.py`, `competitive_agi.py`, `live_deployment.py`, `fully_autonomous_agi.py` — fixed
  poll loops, "domination" framing, **fabricated "live" output**. Gate 3; not a human tell we may copy.
- `ogame_selectors.py`, `_get_basic_knowledge()`, all fixed build/research queues — object truth in
  code. Gate 1.
- **Leaked secrets** (`fully_autonomous_agi.py`, `live_deployment.py`): a live Gemini API key and
  plaintext game credentials in source. Security anti-pattern; never mirror, and never store a secret
  in module code.
- Anti-detection timing (`random.randint(2,8)` s sleeps, uniform jitter) — **a negative reference.**
  Our own numbers are better-sourced (host bot-detector: ≥18 distinct active hours/7 days, never react
  faster than 10 s, ≥6 h dark, heavy-tailed gaps). Do not adopt their timings.

Already recorded, so do not re-mine: RP-022 (`done`); RV-011 phase-classification and RV-012
consultation-confidence-gate (`deferred`, P3, reason in the row).

---

## 6. Decision gates — when would this repo matter?

| Condition | Then |
| --- | --- |
| A failing aspect needs a *number* our corpus lacks and the digest holds | U1's pointer row becomes a canonical-fetch candidate |
| The consultation lane is enabled **and** needs a self-confidence signal | RV-012 (already deferred) |
| Anyone proposes wiring the browser, the per-decision model, or an object list "because the repo has it" | REFUSE — this file is the answer |
| None of the above | **Close the repo as a source; no rows, no spend.** |

---

## 7. Owner actions

- **No new task rows are recommended.** The two mined ideas already live as `RV-011`/`RV-012`
  (deferred); `RP-022` is done. A row here would be OFF THE NORTH STAR — it could not name a failing
  aspect it moves.
- **Optional:** authorise U1 (the pointer note) if you want the differential recorded; otherwise
  record this plan as the closure of `Shinigallo/ogame-agi` against the north star.
- **Do** keep the two confirmations: the event-driven + rule-fallback split, and the consultation
  confidence gate (RV-012) — the latter is the one mechanism the repo has that we half-miss.

---

## 8. One-paragraph summary for the handoff

`Shinigallo/ogame-agi` is an LLM-per-decision Playwright bot: it violates gate 1 (object universe and
thresholds hardcoded in prompts/selectors), the budgets rule (a generative call on the ordinary path),
and gate 3 (fixed poll loops, "domination" framing, fabricated live output), and it ships leaked
secrets. None of its code or tools is admissible inside a module that plays from inside the host. Its
only admissible data is a tertiary strategy digest; used as a *pointer set* to canonical sources it
yields at most one candidate (an expedition ship-size/duration number) that cannot move a failing
aspect today. Recommendation: **refuse the code, close the source, keep the two already-recorded
confirmations.**
