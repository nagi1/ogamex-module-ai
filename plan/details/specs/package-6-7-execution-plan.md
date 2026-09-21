# Package 6 + 7 execution plan — the gated eight, ready to run

Owner direction 17 September 2026: open Package 6, put the universe in cooperative mode, enable the
provider lanes I need, **finish PsychSim fully (sidecar and all)**, and treat these as workable now.

**Status: this is the plan of record. Every decision below is settled and Wave 0 is already applied —
the run starts on the owner's go.** Nothing is blocked on the owner.

Owner budget for this run (from the same direction):

- **DeepSeek** $5 total, across `deepseek-flash` **and** `deepseek-v4-pro`. **Use flash only**; do not
  burn pro. Flash is the testing model.
- **OpenAI** $5 on `gpt-5.6-luna`.
- **Mind peak/off-peak.** DeepSeek peak is **01:00–04:00 and 06:00–10:00 UTC, Mon–Fri**, billed at
  **2×** (`config/routing.php` → `deepseek_peak`, `config/pricing.php` → `peak_multiplier = 2.0`).
  Everything is scheduled for off-peak.

---

## 1. What is already in place (enablers applied)

| # | Enabler | State | Evidence |
| --- | --- | --- | --- |
| E1 | Cooperative universe mode | **DONE** | `SettingsService::setUniverseMode(Cooperative)`; `universeMode()->value == 'cooperative'`. `CooperativeHostilityPolicy` is registered in `AIServiceProvider`. |
| E2 | Provider keys reachable | **DONE** | `/home/nagi/code/ogamex-next/.env`; app reads `DEEPSEEK_API_KEY` (35 chars) and `OPENAI_API_KEY` (164 chars). |
| E3 | Cohort moons for RV-008/009 | **DONE** | Moons `63` (1:8:8) and `64` (8:365:4) for user 13, 6 fields each. |
| E4 | Provider lanes on flash | **DONE (Wave 0)** | `AI_LANGUAGE_ENABLED=true` + `AI_LANGUAGE_MODEL=deepseek-flash`; `AI_CAMPAIGN_CONSULTATION_MODE=observe` + model `deepseek-flash`; routing off. Applied by one `--force-recreate`; ledger read $0.00 at enable time. |
| E5 | Cognition sidecars | **RUNNING** | `ogamex-ai-cognition-cbrkit-1` (:8091), `agentos` (:8093), `fatima` (:8092) — all up and healthy. |

Wave 0 is applied. The paid lanes are live but **managed around test windows** (§3 lane lifecycle).

## 2. What is NOT ready (verification or setup still owed)

| Item | Why it matters | Resolution |
| --- | --- | --- |
| **PsychSim identity** | Owner supplied the canonical repo (`github.com/usc-psychsim/psychsim`). **Verified from primary sources:** MIT license, 100% Python, `setup.py` declares `python_requires='>=3.6'` and **no `install_requires`**, install via `pip install -e git+https://github.com/usc-psychsim/psychsim.git#egg=psychsim`. Last commit ~3 years ago; one tag (`v1.0`) | Remaining spike items: (a) its **undeclared runtime imports** (numpy etc.), (b) whether it runs on the cbrkit base image's **Python 3.13**, (c) whether a headless entry point exists or must be written |
| **AgentOS sidecar** | **Not owed — it is already running** (`ogamex-ai-cognition-agentos-1`, healthy, :8093). `P7-002`'s held-out review calls the real sidecar over HTTP, which is a **local call: zero provider tokens** | Just run `scripts/e2e-agentos-recall-benchmark.php`. No deploy decision. (The Gate 2 finding is about *production adoption* on the 2 GB reference profile, not about measuring here.) |

## 3. Governor rules for the whole run (non-negotiable)

- **Gate 1** — object universe/prices/requirements are host-read; never hardcode an id, machine name,
  price or requirement.
- **Gate 2** — smallest mechanism; no single-implementation abstraction, no config for a constant, no
  forwarding layer. `bash Modules/AI/scripts/ogamex gate` must exit clean.
- **Gate 3** — every mechanism is nameable as ordinary experienced OGame play.
- No `else`/`elseif`; action classes through `app()`; no new dependencies; never `git add -A`.
- **Provider hygiene:** `deepseek-flash` only; off-peak; bounded by the module's daily limits; record
  the usage ledger before/after any real-provider run.
- **Lane lifecycle (owner rule):** the paid lanes stay **ON only while a slice is being tested and its
  results are needed**, and go **OFF once the slice is finished or the run pauses**. Flip the env and
  `--force-recreate` at the start of a provider slice, flip back at its handoff. Nothing runs them by
default.

---

## 4. The plan, in order

### Wave 0 — Enablers — **DONE 17 Sep 2026**

Applied in one `--force-recreate`: `AI_LANGUAGE_ENABLED=true` (flash), `AI_CAMPAIGN_CONSULTATION_MODE=observe`
(flash), `AI_ROUTING_ENABLED=false`; cooperative universe (E1); mandate recorded in `DECISIONS.md`.
No further Wave 0 work.

`observe` mode records a validated recommendation **without applying it**, so 0.2 lets the lane be
exercised without changing any live decision — the safe first step.

### Wave 1 — Package 6 deterministic slices (zero provider calls)

| Step | Task | What ships | Seam | Accept |
| --- | --- | --- | --- | --- |
| 1.1 | **PVE-002** coalition campaign page | module-owned view + route showing campaign progress and both sides' losses | module's own `AiCampaign` + host losses; existing operator page at `admin/ai` is the pattern | page renders live campaign state; no host edit; ordinary-universe page unchanged |
| 1.2 | **DEF-003** alliance/ACS life | the account *acts* in alliance life (apply/join/leave) and can ACS-defend | host `AllianceService`, `AllianceMember`, `AcsDefendMission`; module already *observes* via `RecordObservedAllianceMembership*` | a cohort account joins an alliance through the host path; ACS-defend respects `CooperativeHostilityPolicy`; no object/id hardcoded |
| 1.3 | **PVE-001** campaign momentum | the faction races the same objective as the coalition | `AiCampaign`, `AdvanceAiCampaignStateAction`, `DeclareAiCampaignObjectiveAction` | a campaign fixture shows the faction advancing the contested objective; ordinary universe unchanged |

All three are gate-checked against the cooperative universe set in E1.

### Wave 2 — Package 7 provider slices (bounded provider calls)

| Step | Task | What ships | Gate | Budget |
| --- | --- | --- | --- | --- |
| 2.1 | **P7-001** 7A ordinary-universe consultation | the 6A lane extended to ordinary-universe triggers (war declaration, new colony) | run in `observe`; SDK **fakes** for logic; **one** sanitized real-flash conformance call | ≤ a few flash calls |
| 2.2 | **RV-012** confidence gate | a confidence signal in the consultation brief + suppression of low-confidence nudges | depends on 2.1's lane | none extra (reuses 2.1) |
| 2.3 | **RV-011** game-phase consumer | a phase classifier **only if** a named consumer exists | I propose 2–3 consumers, owner picks one, then I build classifier + that one consumer | none |

### Wave 3 — PsychSim (P7-003), full

| Step | Action | Output |
| --- | --- | --- |
| 3.1 | **Feasibility spike** (largely done) | Verified: `usc-psychsim/psychsim`, **MIT**, Python `>=3.6`, pip-installable from git, no declared deps. Still to pin: undeclared runtime imports, Python-3.13 compatibility, and the headless entry point |
| 3.2 | **Sidecar** | `docker/cognition/psychsim/` (Dockerfile + service on :8080), mirroring `cbrkit`/`agentos` |
| 3.3 | **Module driver** | `app/Contracts/` contract + `app/Infrastructure/Cognition/PsychSim*.php` adapter + selector binding in `AIServiceProvider`, native stays the fallback |
| 3.4 | **Consumer + bounds** | one bounded Theory-of-Mind use (self + 1–3 counterparts, depth 1 per `budgets.md`); never per-tick |
| 3.5 | **Conformance** | a pinned real-adapter run + a Gate 2 verdict recorded |

3.1's remaining pins can still reshape 3.2–3.5 — if PsychSim will not import on a supported
runtime without a heavy dependency tree, that is the finding and the row closes on evidence rather
than shipping a broken sidecar.

### Wave 4 — Deferred evidence

| Step | Task | Gate |
| --- | --- | --- |
| 4.1 | **P7-002** 7B semantic recall | run `scripts/e2e-agentos-recall-benchmark.php` against the **already-running** AgentOS sidecar (local, zero provider tokens); if native is not beaten, close on evidence |

---

## 5. Verification per slice

Owner direction stands: the **quality and coverage gates are deferred** — do not run the full
`quality`/`coverage` passes. Each slice still leaves:
- a runnable check (Pest test or assert-based self-check) — **run only when asked**;
- `bash Modules/AI/scripts/ogamex gate` (Gate 2) — **run only when asked**;
- a before/after figure from artifacts the module already writes, for behavioural slices.

## 6. Explicit risks

1. **PsychSim runtime.** Identity/license/Python are verified (MIT, `>=3.6`, installable). The
   residual risk is its **undeclared** runtime imports and Python-3.13 fit. Wave 3.1's remaining
   pins decide before 3.2 builds.
2. **Live cohort burning budget.** The lanes are live, so the *live* cohort can call the provider.
   Mitigation: the §3 lane lifecycle (off outside a test window), `observe` for consultation, tight
   `daily_limits`, off-peak only.
3. **Cooperative mode is a live change** (already applied in E1). All cohort accounts are faction, so
   AI-vs-AI is unaffected; it matters only for the future human coalition.
4. **The moons I created (E3)** are a live-state edit; symmetric to delete if unwanted.
5. **Un-deferred `RP-*`/`RV-013`** are still audit-closed — the plan does not touch them.

## 7. Ready-to-start — status

**Everything required to start is in place. No item is blocked on the owner.**

| Item | State |
| --- | --- |
| Cooperative universe (E1) | ✅ applied |
| Provider keys (E2) | ✅ reachable |
| RV-008/009 moons (E3) | ✅ exist |
| Wave 0 lanes (E4) | ✅ applied — governed by the §3 lifecycle |
| Cognition sidecars (E5) | ✅ running (cbrkit/agentos/fatima) |
| PsychSim identity/license/Python | ✅ verified; runtime-deps + 3.13 pins are Wave 3.1 work, not owner input |
| `RV-011` design | ✅ decided (§8) |
| `P7-002` sidecar | ✅ not owed — AgentOS already up |

**Ready to run Wave 1 → 2 → 3 in order, per slice, on the owner's go.**

## 8. Decided design choices (this run)

| Choice | Decision |
| --- | --- |
| `RV-011` subject | The **consulting account** (`$trace->perception->playerId`), not the faction. |
| `RV-011` shape | **Raw host-read signals** (rank, completed-research fraction) handed to the model; **no** module-side early/mid/late cut-offs — avoids the hardcoded-levels Gate 1 hit the audit refused. |
| `RV-011` delivery | A **read-only tool** (argument-free, scoped at construction like `LegalCandidatesTool`), registered in the campaign consultation agent's `tools()`. |
| `RV-013` | **Stays cut** — no host self-battle seam. |
| `P7-002` | Run the held-out review against the running AgentOS sidecar (local, free); close on evidence if native still wins. |
| Lanes | On only during a provider slice's test window; off at handoff. |

## 9. Work order — dependency and value

Almost no hard dependencies between the slices (each builds on already-shipped infrastructure), so the
order is set by **value**, with two tie-breakers: **de-risk the biggest unknown cheaply**, and **group
by environment** so the paid lanes are on for exactly one block.

| # | Slice | Why here |
| --- | --- | --- |
| 1 | **PVE-001** campaign momentum | Core cooperative-PvE mechanic (the faction races the objective) — the highest-value Package 6 slice, and the state the coalition page later displays. Deterministic, zero provider cost. |
| 2 | **DEF-003** alliance/ACS life | The account *acting* in alliance life (apply/join/ACS-defend) — a distinct high-value social capability; host seam already exists. Deterministic. |
| 3 | **PVE-002** coalition page | A read-only view of the campaign state that 1–2 produce; cheapest once the state exists; supports the disclosed-cohort validation. |
| 4 | **PsychSim spike (W3.1)** | Cheapest step that can invalidate the whole PsychSim stream — resolves undeclared deps / Python-3.13 / headless entry point *before* any sidecar code. |
| 5 | **PsychSim sidecar → driver → consumer (W3.2–3.4)** | The owner's explicit big ask; provider-free (its own local sidecar), so it groups with the deterministic block. |
| 6 | **PsychSim conformance (W3.5)** | Proves the real-adapter path and records a Gate 2 verdict on the finished driver. |
| 7 | **P7-001 7A consultation** | First provider slice — **lanes ON**. Extends the consultation lane to ordinary-universe triggers. |
| 8 | **RV-011 `GamePhaseTool`** | Small, feeds the same consultation agent; done while the consultation context is fresh. |
| 9 | **RV-012 confidence gate** | Small polish on the consultation lane — **lanes OFF after this**. |
| 10 | **P7-002 recall review** | Evidence-only, no dependency; likely closes on the existing result against the running AgentOS sidecar. |

**Lane windows:** off for 1–6 and 10 (no provider calls); **on only for 7–9** (the Package 7 block).
