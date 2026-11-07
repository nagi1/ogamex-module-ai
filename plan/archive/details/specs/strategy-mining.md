# Strategy Knowledge Mining — execution plan

Status: **research complete** (15 September 2026); implementation blocked on catalog review. This
spec extends the existing plan; it does not replace
[`gameplay-algorithms.md`](gameplay-algorithms.md) (the *how*), [`decision-policies.md`](decision-policies.md)
(the normative *what*), or the [`gap register`](../GAP-REGISTER.md) (the goal-shaped audit). It is the
programme for turning sourced OGame strategy knowledge into a reusable, mapped Strategy Knowledge Base.

## Objective and hard constraints

- Build a **sourced, atomic Strategy Knowledge Base** and map it onto the existing architecture.
- **No gameplay code changes** in this phase. No new scorers until a validated principle cluster requires one.
- **No GoRules**, no learning/bandits, no new infrastructure (owner decisions, 15 September 2026).
- The three [cognition gates](cognition-gates.md) stay the acceptance criteria for every principle.

Pipeline (never "forum post → PHP if statement"):

```
source material → atomic claims → dedup → contradiction analysis → context/assumptions
→ confidence → strategy principle → architecture mapping → (later, after review) implementation
```

## Stage 0 findings (verified against HEAD `5c5fd42`)

- The deterministic decision path is as reported: `DecisionEngine → CandidateActionFactory →
  UtilityScorer → ArchetypePolicy → DecisionTrace`, with `AiDecisionTrace` persistence, replay and
  explain-decision tooling already shipped.
- **One change since the prior audit:** `QueueableSpyPlanner` now skips targets inside the 24 h intel
  window (commit `5c5fd42`). It remains *first-fit among unknown targets* — the target-ranking gap
  stands, the re-probe defect is closed.
- Substantial sourced strategy knowledge **already exists** and must be formalized, not rediscovered:
  [`research/veteran-play.md`](../research/veteran-play.md) (claims + provenance markers),
  [`specs/gameplay-algorithms.md`](gameplay-algorithms.md) (gap→algorithm index E1..AG4),
  [`research/ogame-automation-algorithms.md`](../research/ogame-automation-algorithms.md) (16-bot survey,
  patterns P1–P13), [`research/strategy-simulation-and-bot-patterns.md`](../research/strategy-simulation-and-bot-patterns.md),
  [`research/host-capability-map.md`](../research/host-capability-map.md) (domain facts).

## Disagreements recorded against the handoff

1. The Strategy KB is **mostly formalizing** existing knowledge; only the thin domains need new research.
2. Confidence letters A/B/C/D are **derived** from the existing provenance markers
   (`MEASURED / DOCUMENTED / ANECDOTAL / CONTRADICTED / ESTIMATE / NOT FOUND`, and
   `host / verified / documented / contested / placeholder / synthesis`), never a parallel taxonomy.
3. **Fleetcrash / phalanx / ninja / ACS is the largest real gap**: no algorithm block, no executor,
   host surfaces unverified. Mark `unsupported currently`; never discard.
4. Research (R1/R2) and basic colonization (CL1/CL2) are shipped — do not spend research tokens there.
5. `StrategicPosture` stays an open question; revisit only if the raid/fleetsave corpus repeatedly needs
   a temporary operating state (today's hook: `PerceptionSnapshot::recovery_factor` + `ArchetypePolicy`).
6. Do not pre-build `TargetScorer` / `InvestmentScorer`; let clusters emerge and slot into the existing
   `DecisionTrace` component mechanism.

## Stages

### Stage 1 — Source discovery (scoped to gaps)

Build/update [`research/source-registry.md`](../research/source-registry.md). Do **not** rediscover
economy/raid-basic/research sources already cited in `veteran-play.md`. Two discovery streams:

- **S1 PvP & fleet:** fleetcrash profitability, recycler/debris timing, phalanx (blind/return), ninja,
  ACS attack/defend, fleet composition by account stage, ship ratios/counters.
- **S2 intelligence & survival & colonies:** espionage cadence, activity detection, proactive fleetsave,
  colony positioning/timing, moon strategy.

Discord: public/indexable material only; record the limitation where inaccessible; never fabricate access.

### Stage 2 — Domain research (reuse the registry; domain-ordered)

| # | Domain | Mode | Existing anchor |
| --- | --- | --- | --- |
| 1 | Economy / ROI / expansion | **Formalize** | `veteran-play` §1–3, E1/E3/Y1 → `EconomyUpgrades`, `EnergyCapacity` |
| 2 | Raid & target selection | **New** (activity risk, intel freshness, travel opportunity cost, personality, relationship) | `RaidPlanner`, `NativeRaidEstimator` |
| 3 | Espionage / intelligence | **New** (target prioritization, activity detection, phalanx intel) | `QueueableSpyPlanner` |
| 4 | Fleetsave / survival | **New** (proactive exposure beyond reactive V1) | `QueueableFleetSavePlanner`, `SaveFailurePolicy` |
| 5 | Fleet composition | **New** (counters, fodder, recycler/cargo sizing by stage) | `QueueableUnitPlanner` |
| 6 | Fleetcrash / recycler / phalanx | **New** (largest gap; Pass-4 niche) | none |
| 7 | Research priorities | **Formalize** (R1/R2) | building planner |
| 8 | Colonization | **Light new** (positioning/timing beyond CL1/CL2) | `QueueableColonyPlanner` |
| 9 | Alliance / diplomacy / war | **Defer** to Package 6 | `NativeSocialCognition` |

Progressive passes: **Pass 1** core → **Pass 2** gaps → **Pass 3** contradictions → **Pass 4** advanced
(fleetcrash/ninja/moon/ACS) only after core is strong.

### Review passes (after domain research)

1. **Dedup** — merge equivalent claims, keep provenance.
2. **Skeptic** — outdated? single-player preference? mechanically valid? threshold supported?
3. **Mechanics** — mark `supported / partially supported / unsupported currently` against
   `host-capability-map.md`; never discard unsupported mechanics.
4. **Architecture mapper** — map each principle to current code + missing signals (no implementation).

## Artifacts

- `research/strategy/sources.yaml` — reusable source index (**done**).
- `research/strategy/principles/*.yaml` — atomic Strategy Knowledge Catalog (**done: 107 principles across 13 domains**).
- `research/strategy/claims/*.yaml` — atomic claim layer + claim-type classification (**done**).
- `research/strategy/coverage.yaml` — the per-domain coverage matrix (**done**).

  The [`strategy/`](research/strategy/README.md) YAML store is the single authority; the Markdown
  catalogs (`source-registry.md`, `strategy-principles.md`, `strategy-claims.md`) remain the human
  narrative and are derived from it.
- `research/architecture-mapping.md` — every researched principle mapped to current code (**done**).
- `research/classical-ai-patterns.md` — classical game AI pattern catalog (**done**).
- `specs/gameplay-algorithms.md` — F-series (fleetcrash/phalanx/moon) and richer N/T/FS blocks (**done**).
- `GAP-REGISTER.md` — wave-6 strategy-depth gap scan (**done**).
- `DECISIONS.md` — the mining decision and the formalize-vs-new split (**done**).
- `reviews/` — one cheap, machine-parsable review record per pass (**done**).

## Coverage matrix (final — authoritative copy in [`research/strategy/coverage.yaml`](research/strategy/coverage.yaml))

The research has landed: **107 principles across 13 domains** (21 shipped, 7 partial, 76 researched,
3 deferred, 0 gap). The gap pass (3 agents, 15 Sep) closed the last unsourced domains — ninja/baiting,
expeditions, non-English (DE/PL), fleet composition, moon economics and colony positioning — adding
26 sources and 24 principles plus two new domains (NIN, EXP). Remaining open discovery: ACS tutorials
ORG-009/010, the French board guide library URLs, and `ogamewiki.de`. The claim-type classification
lives in [`research/strategy/claims/types.yaml`](research/strategy/claims/types.yaml). This file no
longer maintains a second copy of the matrix.

## Classical game AI reverse engineering (milestones 6–8)

The brief's third workstream is classical-game-AI pattern extraction. It was completed from the
brief's own confirmed findings rather than rediscovered (the brief names the source files and says
the facts are already found). Output:
[`research/classical-ai-patterns.md`](research/classical-ai-patterns.md).

- **M6 — Zero Hour** (EA source + `FreemanZY` data): 10 patterns (ZH-1..10) — state→parameter
  cadence, derived world-state signals, master counter, difficulty cadence, context-gated
  candidates, attack-priority sets, sequential plans, success/failure feedback, SkillSets,
  engine-primitives-plus-data.
- **M7 — Freelancer**: 6 patterns (FL-1..6) — composable behaviour blocks, inheritance-as-delta,
  few strategic parameters, difficulty-as-quality, cheat-difficulty (negative lesson), profile
  simplification.
- **M8 — Cross-game synthesis**: OpenRA (OA-1/2), Cobra (CB-1), Wesnoth (WE-1..4).

M6 and M7 are **pattern-level**, not diff-level: the patterns were formalized from the brief's
confirmed findings (named source files) rather than obtained, normalized and diffed against the
Advanced AI Mod / Freelancer enhancement mods. The actual file-diff comparison is recorded as a
**deferred follow-up**, not a blocker — the reusable patterns are what the mapping consumes.

Three takeaways that survive the mapping: **(1)** state → parameter modifier → behaviour is the one
reusable shape and needs no class hierarchy; **(2)** difficulty-as-quality and variety-as-seeded-choice
are already in the design — the work is reaching the tactical planners with them; **(3)** the
classical corpus adds *confidence* to mechanisms the strategy catalog already named (per-mission
target score → T6, cached deterministic simulation → H10, composition-aware production/launch → FLE/U,
bounded outcome feedback → H8, derived threat features → SP7), not new ones.

## Hypotheses under review (H1–H10)

The brief lists ten hypotheses and orders the agents to look for reasons they are **wrong**. Status
against the verified code and the two catalogues:

| H | Claim | Evidence | Status |
| --- | --- | --- | --- |
| H1 | Tactical intelligence should use candidate scoring, not first-fit/fixed order | `QueueableSpyPlanner` id-order, `QueueableFleetSavePlanner` first-planet, `QueueableUnitPlanner` fixed roles — code-read | **holds**; N4/V7/T6 written |
| H2 | Archetype should influence tactics, not only intent | `ArchetypePolicy` feeds action selection; planners are archetype-blind | **holds**; T6 relationship/archetype term |
| H3 | A small `BehaviorProfile` surface beats archetype-specific planners | FL-1/3, OA-1/2, FL-6 all agree | **holds**; recorded as a refusal |
| H4 | Temporary `StrategicPosture` may help | ZH-3, WE-4 offer counters/states; `ArchetypePolicy` + `AiSkillBand` + `recovery_factor` already cover the cases found | **open** — not justified yet |
| H5 | Target selection needs profit/risk/activity/freshness/distance/exposure/relationship | RAID-004..013, INT-003/004 researched | **holds**; T6 |
| H6 | Structured historical opponent patterns | activity reader (SP7) + per-planet stamps; AgentOS unnecessary for schedules | **holds** — `time_last_update` + SQL beats AgentOS here |
| H7 | Fleet production and launch composition are separate decisions | WE-2 + FLE corpus | **holds**; production = U-series (next increment), launch = T6 subset |
| H8 | Bounded recent-outcome modifiers without ML | ZH-8; `recovery_factor` + trace outcomes are the raw material | **plausible**; no new persistence |
| H9 | Difficulty modifies decision quality, not cheats | ZH-4, FL-4/5; `AiSkillBand` already does this shape | **holds**, already designed |
| H10 | Cache expensive simulation when inputs are equivalent | WE-1; T2 sampling is already seeded/deterministic | **holds**; input-hash cache in T2 |

The brief's anti-bias questions — are profiles needed, does posture duplicate intents, does scoring
make planners opaque, is CBRKit/FAtiMA pulling weight, is AgentOS needed for schedules, are mods
smarter only because they cheat — are answered in [`DECISIONS.md`](DECISIONS.md) and the
[`reviews/`](reviews/) record; the answers that survived are the table above.

## Integration gates (L)

No gameplay implementation begins until the research cluster it consumes has passed all ten,
in order. The first four are the review passes of this programme; the rest are the handoff's
acceptance checks.

1. **Source coverage** — the cluster's claims are sourced; a gap domain has at least two strong
   independent sources or is mechanically derivable.
2. **Deduplication** — equivalent claims merged, provenance kept.
3. **Confidence classification** — every claim carries A/B/C/D; implementation candidates are A/B.
4. **Contradiction review** — disagreements recorded verbatim in [`research/strategy/claims/contested.yaml`](research/strategy/claims/contested.yaml),
   never silently resolved.
5. **Mechanics validation** — marked supported / partially supported / unsupported against
   [`host-capability-map.md`](research/host-capability-map.md); unsupported mechanics are never discarded.
6. **Current-code mapping** — named current location + missing signals in
   [`architecture-mapping.md`](research/architecture-mapping.md).
7. **Expected benefit** — a named observable improvement (a signal the pilot can read), not a vibe.
8. **Proposed tests** — the acceptance evidence the algorithm block's `Accept` line already names.
9. **No duplication** — the change does not reimplement a host capability or an external driver.
10. **Architecture review** — gate 1 (host-derived, no hardcoded object), gate 2 (smallest
    mechanism), gate 3 (namable as ordinary experienced play), via `bash scripts/ogamex gate`.

A cluster that passes all ten is a reviewable implementation slice; the delivery order for the
wave-6 slices is steps 13–18 of [`gameplay-algorithms.md`](gameplay-algorithms.md#delivery-order).
The one-stop task index for implementation — every remaining task, its plan references, status,
notes and dependency graph — is [`../../tasks/USAGE.md`](../../tasks/USAGE.md) (SQLite
`../../tasks/tasks.db`).
