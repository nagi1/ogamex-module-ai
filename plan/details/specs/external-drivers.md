# External drivers — full utilization and native↔external collaboration (Package 5)

Status: the specification for Package 5, written 15 September 2026 from the owner's decision that the
optional external drivers are a first-class capability rather than a swap-only exercise. Package 5 is
implemented on 15 September 2026 and measured against the real sidecars; the milestones below mark
what shipped and what is deferred. It extends the Phase 3 driver slices (3I) and is gated by this
spec's own acceptance, not by the PvE completion gate.

## Why this is its own package

Slice 3I proved each driver can answer its contract and degrade to native per call. It did not make
the drivers earn their keep, and three code-reads now name exactly where:

| Driver | Wired today | Discarded today |
| --- | --- | --- |
| FAtiMA affect | `POST /scenarios` + beliefs + perception + `GET /emotions` (5 round trips) | Mood, the decision asset's chosen intention (`GET /decisions`), social-importance dynamics, the autobiographical memory, and every emotion in the pool after the first. The PHP adapter pre-selects the action label (`branch()`) and signs one value, so the driver's authored rules translate rather than appraise. |
| FAtiMA/CiF social | `POST /scenarios` + rapport belief + `POST /socialexchanges` (3 round trips) | The per-mode volition **magnitudes** and the protocol **step** are reduced to "is the volition set empty". `respect`, `socialImportance`, `anger` and `threat` never reach the driver. The result is a binary veto, not evidence. |
| CBRKit | `POST /retrieve` (1 round trip) | `retriever.py` is a verbatim port of `NativeExperienceEngine::similarity()`, so the sidecar reproduces the native formula bit-for-bit and its own retrieval measure is never used. |
| AgentOS | `POST /recall` (1 round trip) | `score`, `relevance`, `encodingStrength`, `stability`, provenance and PAD context are computed and thrown away; only the id order is consumed, over a deterministic lexical embedder. And `LongTermMemory` has **no production caller**: nothing resolves it outside the provider binding. |

The owner runs a host with measured headroom and wants every available driver used to make real,
more-intelligent choices. This package closes the distance between "wired and fallback-safe" and
"actually used, together with native".

## Non-negotiables carried in

- **The three [cognition gates](cognition-gates.md).** Gate 1 — no static hardcoded AI: nothing here
  introduces an object list; driver inputs come from the host and module tables. Gate 2 — relatively
  simple: one mode knob and one combiner method per contract, no `DriverManager`, no `CognitiveKernel`,
  no forwarding layer. Gate 3 — every mechanism is nameable as something a human player does; the
  human play each combiner imitates is written next to it.
- **No duplicated driver capability** ([phase-3-cognition.md](phase-3-cognition.md#no-duplicated-driver-capability)).
  The combiners are module policy over driver outputs — scope, attribution, permission,
  current-validity, validation, budgets, persistence, failure mapping, translation and weighting. They
  never port a driver's algorithm to PHP and never add a native equivalent for comparison.
- **Best AI first, optimise later (owner, 16 September 2026).** The goal is the strongest account we
  can build; the 2 vCPU / 2 GB profile is an optimisation target, not a gate. Hybrid mode is the
  default, with native as the floor and every selected driver contributing alongside it.

## The collaboration model

Today each selector resolves exactly one engine and the external engine wraps native only as a
per-call fallback. The change is one new mode value in which **native always runs and the selected
external driver runs alongside it when it is healthy and can contribute**; a module-owned combiner
merges the two answers.

```
ai.cognition.mode = native | external | hybrid        (default: hybrid)
```

- **`native`** — the `*.driver` settings are ignored; every contract resolves to its native engine.
  This is the reference profile and the ablation baseline.
- **`external`** — today's behaviour: the external engine replaces native, with native as the per-call
  fallback. Kept unchanged, because a driver *swap* (ablation D/F) must measure a driver against
  native, not against a merge.
- **`hybrid`** — native is the floor; the driver contributes its own signal; the combiner blends them.
  This is the "both work together" mode the owner asked for, and the only new mechanism in the package.

One mode knob applies to all three driver settings. The individual `*.driver` settings still say
*which* external engine; the mode says *how* it is used. That is the smallest mechanism that expresses
the three comparisons the harness needs (native baseline, driver swap, both together) without a
per-contract mode matrix.

### Affect — native taxonomy + driver depth

Native keeps producing the module's three-emotion `AffectAppraisal`; the episode and affect-state
records stay keyed on that taxonomy. FAtiMA, when healthy, contributes what native does not model:
**mood** (the driver's valence after the appraisal), **social importance** (its relational judgement)
and the decision asset's **chosen intention** (`GET /decisions`: protect-self, seek-support,
retaliate, reconcile).

`AffectAppraisal` widens with nullable `mood`, `socialImportance` and `copingIntention`. Native fills
them with its own bounded defaults or null; FAtiMA fills them from the driver. The consumer —
`AppraiseObservedBattleReportAction` into the episode/affect state, and `ResolveCognitiveIntentAction`
for the intention — consumes them when present. Mood sustains or fades anger across sessions (the human
play: "a grudge that outlives the incident"); social importance feeds relationship writes ("warmer to
the ally, colder to the betrayer"); a coping intention maps to an existing policy input and never to a
fleet launch.

The adapter stops reducing the stimulus to a single pre-chosen action label. It sends the full signed
appraisal dimensions the stimulus already carries — aid, harm, threat, relationship, archetype — and
the authored scenario rules select the emotion. Signing the appraisal variables remains the module's
job, because that is OCC's division of labour (the app judges desirability and praiseworthiness; the
engine computes the emotion); what changes is that the driver's rules, not a PHP `branch()`, choose the
result. The returned emotion is still clamped and mapped onto the module's three-emotion taxonomy, and
an OCC emotion outside it still degrades to native rather than being approximated.

### Social — native stance + CiF volition as evidence

Native keeps producing the authoritative stance — response, reason and counter-terms — over the
module's hard constraints (exact terms, outstanding commitments, available resources). CiF contributes
the **per-mode volition magnitude and the protocol step** for the exchange, with `respect`,
`socialImportance`, `anger` and `threat` finally reaching the driver's rapport and influence rules.

`SocialExchangeEvaluation` widens with nullable `volition` and `step` (driver evidence). The combiner:

1. refuses to grant anything native refused (unchanged — cognition cannot authorise what the module would not);
2. withholds an acceptance when the driver reports the exchange cannot start (today's veto, kept);
3. demotes a native `Accept` to `Clarify` when the driver reports a present but weak volition for the current step — the driver now *informs* confidence instead of flipping a bit.

That is the human play "I only accept when I actually want to, and a lukewarm deal gets questioned",
which the binary veto cannot express.

### Experience — native floor + the driver's own measure

Native keeps computing the deterministic ranking and the module's tie-break (the floor). CBRKit stops
executing the module's formula and starts using **its own retrieval measure** — a per-feature weighted
retriever, not a port of `NativeExperienceEngine::similarity()`. The ported `retriever.py` is deleted.

The combiner: native supplies the candidate set and the deterministic tie-break; CBRKit supplies its
own similarity order; the merged order is the driver's order with the module's tie-break and similarity
floor applied on top. `RankedExperience` widens with a nullable `driverSimilarity` (the driver's own
score), so a review and the conformance command can tell a native order from a driver order.

`cbrkit.eval` over held-out real outcomes stays the recorded measure of the gain CBRKit adds over the
native uniform mean (the ≥5 pp top-k target); hybrid is the default, and the measured comparison says
exactly which order each engine contributed.

### Memory — a real caller first, then the driver's ranking

Native keeps supplying the authoritative, scoped, redaction-aware fact set; AgentOS keeps ranking a
candidate set the module sends, storing nothing. The merge depends on the configured cognition mode
(`AgentOsLongTermMemory` owns it): `external` lets the driver's ranking decide which facts survive the
caller's limit with the native recency floor beneath it, and `hybrid` keeps the native recency set
and uses the driver's ranking only to reorder within it, so relevance floats to the front without
evicting recency. Two things are missing and both are in scope:

1. **A caller.** `ContextBuilder` packs caller-supplied sections and never consults `LongTermMemory`.
   The hybrid only matters once recalled history reaches a decision or a reply, so this package wires
   the call: the account's old facts about a counterparty (relationship, obligations, prior exchanges)
   are recalled into social evaluation and the language context path.
2. **The driver's diagnostics.** `AgentOsLongTermMemory` reorders by id and discards `score`,
   `relevance`, `encodingStrength` and `stability`. The recall return widens with a nullable per-fact
   `relevance` so the consumer can weight a memory. The sidecar's `local-embedding-manager.mjs` stays
   the deterministic lexical embedder until an approved semantic experiment — the reference profile
   cannot host a local model, and a hosted embedder is a budgeted provider call, not a default.

## Milestones and completion evidence

| Slice | Deliverable | Proof before moving on |
| --- | --- | --- |
| 5A — the mode and the widened contracts | `AiCognitionMode` (`native`/`external`/`hybrid`), the `ai.cognition.mode` key, selector arms for hybrid, and the four widened return types with native-safe nullable defaults. | Absence stays free: with no config every contract resolves to native exactly as today and the widened fields are null; every existing test passes unchanged. |
| 5B — FAtiMA affect to full depth | The adapter sends the full signed stimulus dimensions and reads back mood, social importance and the decision asset's intention alongside the mapped emotion; the intention maps through `ResolveCognitiveIntentAction`. | The same battle stimulus yields a driver-chosen emotion plus a mood figure and a mapped intention; an unmappable emotion still degrades to native; no intention ever grants a fleet action. |
| 5C — CiF social as evidence | Pass `respect`/`socialImportance`/`anger`/`threat` to the driver; read per-mode volition and step; withhold and demote per the combiner. | Weak-but-present volition demotes an `Accept` to `Clarify`; empty volition withholds; the driver never grants a native refusal; absent persona/counterparty still answers natively. |
| 5D — CBRKit's own measure | Delete the ported `retriever.py`; ship a real `cbrkit` retriever; widen `RankedExperience` with `driverSimilarity`. | Driver order differs from native where its measure differs; the tie-break is still the module's; `cbrkit.eval` over held-out outcomes is measured and recorded. |
| 5E — memory caller and diagnostics | Wire `ContextBuilder`/social evaluation to recall through `LongTermMemory`; surface per-fact relevance. | A counterparty's old facts reach the evaluation/context; native recency order is the fallback; deleted facts never surface under either engine. |
| 5F — hybrid conformance and the measured verdict | Extend `ai:cognition-conformance` to the hybrid mode; measure latency, cost and behaviour; record the Gate 2 verdict per driver. | Native default unchanged; hybrid opt-in; the recorded numbers name which driver earned its hop and which is still only reordering. |

## Package acceptance

With `ai.cognition.mode = hybrid` (the default) each selected driver contributes its own signal *in
addition to* the native floor, every failure degrades per call to native alone, no driver can grant
what the module refuses, and the measured comparison (`ai:cognition-conformance`) names the gain each
driver adds. `native` stays the ablation baseline and the reference-profile fallback; driver-swap
evidence (`external`) stays available for the ablation, unchanged.

## What this package does not do

- It does not enable a driver as a hard requirement; a missing sidecar degrades to native.
- It does not write PHP that duplicates a driver's algorithm; the combiners are module policy over
  driver outputs.
- It does not add PsychSim, embeddings, ML compression or semantic retrieval; those stay behind their
  own activation gates.
- It does not add a `DriverManager`, a framework split or a second player runtime.

## Shipped vs deferred (16 September 2026)

Shipped and measured: the `native | external | hybrid` mode with per-contract combiners (5A), the
widened contracts, the hybrid affect depth via mood and the driver's own judgement (5B, mood only),
the CiF volition/step evidence with withhold and demote (5C), the hybrid experience merge with
`driverSimilarity` (5D, module side), the memory caller plus the native-floor reorder (5E), and —
closing 5D on 16 September 2026 — CBRKit's own per-feature weighted measure with categorical object
and planet identity (`docker/cognition/cbrkit/retriever.py`) plus its held-out comparison harness
(`eval_retriever.py`).

Closed by decision, not code (16 September 2026):

- **FAtiMA social importance and decision intention.** No verified consumer shape exists: the module
  has no caller that would consume the social-importance asset or the `GET /decisions` intention, and
  building one without a caller is the speculative machinery gate 2 forbids. Closed as deferred until
  a consumer is named; the seam and the adapter read stay available.
- **Per-fact relevance surface.** AgentOS emits relevance scores, but no consumer weighs them.
  Surfacing the field is one change once the language context path needs it; closed as deferred.
- **The memory driver's consumer, measured.** The approval test
  (`scripts/e2e-agentos-recall-benchmark.php`, 15 September 2026) shows the value is substitution, not
  addition: the +33.3 pp required-fact recall comes from a full `topK` promotion that evicts the
  newest fact in 29 of 30 trials, and the only production reader sits behind an
  `availableAmount = 0` branch with no query text. Gate 2 therefore stays unmet; closed as disabled
  on evidence: [driver decision record](../research/phase-3-driver-decisions.md).
