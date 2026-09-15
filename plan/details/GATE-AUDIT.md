# Gate audit — the whole module against the three gates

Recorded 14 September 2026, at the owner's instruction, after the gates were written down. The
[cognition gates](specs/cognition-gates.md) apply to every slice that already exists, not only to the
next one, so this is a pass over `Modules/AI/app` and `Modules/AI/config` looking for each gate's
forbidden shape.

Method, in the order the gates are numbered:

1. **Gate 1** — read every object identifier, machine name, requirement, price, planet-type rule and
   queue rule the module states, and ask whether the host already owns that answer.
2. **Gate 2** — look for a mechanism with one implementation, a value that can never vary, a
   measurement nobody took, a layer that only forwards, and anything a slice left dead.
3. **Gate 3** — for each behaviour the module performs, name the ordinary play it imitates. A
   behaviour nobody can name is a finding whatever else it does.

## Gate 1 — static hardcoded AI

| # | Finding | Evidence | Disposition |
| --- | --- | --- | --- |
| A1 | `AiFacility` named `robot_factory`, `shipyard` and `research_lab`, and `AiCapability::requiredFacility()` mapped each capability onto one of them, so the chain that unblocks research and units would have been blind to a facility a mod added. | code-read | **Closed in slice 3N.** Both were deleted; `FacilityChain` derives the steps from `getResearchObjects()`, `getUnitObjects()`, `getRecursiveRequirements()` and the host's object types. |
| A2 | `FirstBuildingTarget` names four object ids (1, 2, 3, 4) and is the entire set of economy buildings the account may choose, so a resource building a mod adds can never be built by it. | code-read | **Closed in slice 3P.** The enum is deleted. `EconomyUpgrades` builds its candidates from `ObjectService::getGameObjectsWithProduction()` and `getBuildingObjectsWithStorage()`, so the set is whatever the host reports as producing or storing — including a station a mod adds. Obligation 10 is closed by evidence: `StationObject` carries no `storage` field, so no station can store in this host. |
| A3 | `AiBuildingMachineName` names `shipyard` and `nano_factory` to restate a game rule that the host enforces in its **building controller** (object ids 21 and 15) and again while it processes the queue — but in no service the module can ask. | code-read + host source | **Closed 15 September 2026.** R2 landed: `PlayerService::isObjectUpgradeBlocked(int)` publishes the host's own rule, the controller reuses it, and the module's enum is deleted. |
| A4 | `AiProfileSettings::BUILDING_WEIGHTS` is a per-profile map from object name to weight, read by the scoring policy and written by nothing in production, so the object names in it decide the build order. | code-read | **Closed in slice 3P.** The settings key and its accessor are deleted with the policies that read it. |

Audited clean:

- No other OGame object machine name appears in `app/` or `config/` — verified by grepping the
  catalogue's own vocabulary (52 object names) across the module.
- Every kind, price, requirement, planet-type and affordability answer comes from `ObjectService`,
  `PlanetService`, `BuildingQueueService`, `ResearchQueueService` or `UnitQueueService`.
- The capability vocabulary (`AiCapability`, `AiCandidateActionType`, `AiActionType`) is module policy
  about *intents and actions*, not a list of objects, and is what the register calls allowed.
- `AiSocialResource` names the three OGame resources. Resources are not extensible in this game, and
  the alternative — asking the host for "the resources that exist" — has no consumer that would
  behave differently, so naming them is not a gate-1 defect.

## Gate 2 — relatively simple, never over-engineered

| # | Finding | Evidence | Disposition |
| --- | --- | --- | --- |
| B1 | `energy_blocker` is a scored feature with a weight of 20 that no caller ever sets to anything but `0.0`, so twenty points of every action score are a constant and an energy signal that was meant to exist never arrived. | code-read | **Closed in slice 3P.** The scored feature and its weight are gone; energy is now a real rule (`Domain/Decision/EnergyCapacity` offers the host-reported energy producers when the planet runs a deficit), not a constant score term. |
| B2 | `AiBuildingMachineName` is a two-case enum with one caller. | code-read | **Closed 15 September 2026.** The host now owns the rule (R2: `PlayerService::isObjectUpgradeBlocked`), the controller reuses it, and the enum is deleted. |
| B3 | `FirstBuildingTarget`, `BuildingScoringPolicy`, its two implementations and a settings key exist to order four objects by a preference nothing provisions; the same order falls out of two host numbers — the production a level adds and the price it costs. | code-read | **Closed in slice 3P.** One class, `EconomyUpgrades`, scores every host candidate by payback and deletes the enum, the contract, both implementations and the settings key. The experience-informed nudge survives, folded in as a term bounded to a fifth of a payback, so it resolves a near-tie without being able to outrank the arithmetic — and the ablation switch that proves it is off remains. |
| B4 | `ArchetypePolicyResolver`, `ContextBuilder`, `QueueAiBuilding` and `RunAiSession` are contracts with a single implementation. | code-read | **Deliberate.** These are the seam list the plan commits to — the host action gateway, the session runner and the model-backed context builder are exactly what a host change or a test must be able to replace, and two of them are already replaced in tests. A contract with no swap and no test override would be the defect this entry describes; none of these is that. |
| B5 | Nothing else was found: no cache, no optimisation without a measurement, and no layer that only forwards. The adapters call host services and return their results rather than wrapping them twice. | code-read | Clean. |
| F1 | `RaidEstimator` is a contract with one implementation (`NativeRaidEstimator`) and no test override — the test instantiates the concrete class directly. | code-read + gate scan | **Closed 15 September 2026.** The interface is collapsed into `NativeRaidEstimator`; `RaidPlanner` type-hints the concrete class and the provider binding is dropped. |

These Gate 2 findings are re-checked mechanically by the standing gate — `scripts/gate-2-review.php`,
wired into `scripts/ogamex gate` and `scripts/ogamex quality`, and judged by the Gate 2 reviewer agent
(`.github/agents/gate-2-reviewer.agent.md`). The procedure is in
[specs/overengineering-gate.md](specs/overengineering-gate.md).

## Gate 3 — what a good professional OGame player does

| # | Finding | Evidence | Disposition |
| --- | --- | --- | --- |
| C1 | **The account ignores energy.** The host throttles a planet's whole production by the energy it can cover (`PlanetService::updateResourceProductionStatsInner` multiplies by the consumption ratio), so a planet with mines and no plant produces a fraction of what its mines say. Nothing in the module reads energy; the only mention of it is a persona weight on the solar plant, and A4 means that weight is never provisioned. No player plays this way — energy is the first thing they fix. | code-read + host source | **Open, highest severity.** Before anything else, a planet running a deficit wants capacity, chosen from the objects the host says produce energy. |
| C2 | The build order is arbitrary. With no provisioned weights the seeded policy scores the four targets as `100 + 0 + crc32(seed, target) % 10`, so each account has a fixed but meaningless order. It cannot be named as something a player does. | code-read | **Closed in slice 3P.** The order is the host arithmetic: production the next level adds ÷ weighted price, with the host's build time breaking a tie. The persona's taste is a seeded nudge of at most 4% either way, so it cannot reorder anything except a near-tie — measured: two mines on one planet came out 20% apart, so the nudge never moved them. |
| C3 | The module refuses a shipyard or nanite-factory upgrade while units are building. Checked against the host: this is the host's own rule, stated in its building controller, so the refusal is correct play and wastes no action — but the module states it instead of asking (see A3). | code-read + host source | **Closed 15 September 2026.** The module now asks the host (`isObjectUpgradeBlocked`, R2) instead of stating the two objects. |
| C4 | The behaviours checked and named: fleetsave before raiding, raids only from published reports, authored dialogue before any provider, an observation path that cannot write, delivery through the host's own chat, caps that stop work rather than discard it, and profile-off meaning no action at all. | code-read | Clean — each is either play a player performs or a mechanism invisible to one. |
| C5 | Storage capacity is not a build rule, so a planet whose resource caps out gains nothing from more mining and the account does not notice. A player builds storage. | code-read | **Closed in slice 3P.** Storage joins the same ranking, ahead of the mines when it is about to overflow: a storage object is offered when the time to fill its remaining capacity is under 48 hours — the guides' "hold 24–48 hours of production" — and only for a resource the planet actually produces. |

## What the research settled

Four research notes were taken after this audit, and they changed three dispositions and one plan:

- [`research/veteran-play.md`](research/veteran-play.md) — published guides, the official rules and a live
  universe snapshot. It confirms the payback rule in the players' own words ("prioritize mines with lowest
  amortization"), gives the storage trigger, the raid profit test and the bashing limit, and it records
  that **energy doctrine is genuinely contested**: one guide builds through deficits deliberately for
  return, the other plans two levels ahead. The module takes the predictive doctrine and says why.
- [`research/ogame-automation-algorithms.md`](research/ogame-automation-algorithms.md) — eleven public
  automation projects read for their decision algorithms. They converged on exactly the patterns this
  audit recommends (payback ordering, requirement closure, threshold rules, one-action-then-sleep,
  profit accounting, a fleet-save state machine, jittered scheduling) **and stored their game knowledge
  in hardcoded tables, which is the thing gate 1 forbids.**
- [`specs/decision-techniques.md`](specs/decision-techniques.md) — the technique comparison and the
  "do not build" list, which is the answer to "least dependence on language models": the recommended
  techniques are arithmetic over host-quoted numbers, and every heavier technique is rejected with a
  reason.
- The activity parameters in `veteran-play.md` §9 are what G10 and G11 now have to be built against.

Two corrections to this audit came out of it:

- **C2 is no longer only "arbitrary" — the replacement is specified.** Order the economy by the
  production a level adds against the weighted price it costs. A **second, deeper source pass corrected
  the second half of this sentence**: the first version said to compare "return per hour of queue" when
  two candidates are close, and no project in the corpus computes that metric — the one that computes a
  construction time never feeds it back into its ordering key. It is now a tie-break, and the comparison
  that would justify promoting it is recorded in
  [`specs/gameplay-algorithms.md`](specs/gameplay-algorithms.md#e2-queue-occupancy-honest-about-what-is-not-proven).
- **C5 (storage) is promoted from "deliberate for now" to planned**, since the sources agree on the
  trigger: upgrade storage when the time to fill the remaining capacity is shorter than the time until
  the next planned spend.

## What the second research round settled

A deeper pass on 14 September 2026 re-read sixteen automation projects at source level, re-fetched every
guide link and surveyed the host itself. It changed four things in this audit and produced two new
documents — [`specs/gameplay-algorithms.md`](specs/gameplay-algorithms.md), which is now the execution
strategy for every open finding, and
[`research/host-capability-map.md`](research/host-capability-map.md), the host survey behind it. What it
changed here:

- **A3 and B2 are now a two-part host obligation.** The queue rule is not the only thing the module
  restates: vacation mode blocks queue *additions* in the controllers only, the fleet-recall ownership
  check lives in the fleet controller, and the expedition holding-hours and fleet-speed bounds are
  controller-only too. The capability map lists all ten, and the module's `AiBuildingMachineName` argument
  now sits inside a general pattern rather than being a lone exception.
- **A new, harder gate-3 constraint was found that this audit had not considered**: the host's own admin
  detector flags an account with 18 or more distinct active hours in a 7-day window, a sub-10-second
  reaction to an attack, or an expedition re-dispatched within 10 seconds. That is a measurable
  acceptance test for C1's neighbourhood — the routine — and it is what G10 and G11 are now built
  against ([H1](specs/gameplay-algorithms.md#h1--the-active-hours-constraint)). It also means the audit's
  C-series had no entry for the *shape of the day*, which is now recorded rather than assumed.
- **The raid finding gains teeth.** The literature and the tools agree that a mean is not an answer (a
  documented 60 M inversion), that nobody models defender uncertainty, and that the host's battle entry
  point is neither seedable nor side-effect free — so the estimator's parameters are fixed in the
  algorithms spec and the "leave raids recorded" disposition stands until the host changes.
- **The "one measurement the environment can change" rule paid off again.** Every claim in the new
  research documents was re-fetched rather than trusted, and seven earlier claims had to be retracted or
  corrected, including two in this audit's own supporting notes. The retractions are listed in
  [`research/veteran-play.md` §11](research/veteran-play.md#11-verification-status) and in the corrections
  section of the algorithms spec.

## Order of work

1. **Slice 3O — energy becomes a rule (C1, B1).** The dead `energy_blocker` feature is deleted, and a planet that cannot cover its consumption wants capacity before anything else, derived from the host's own production numbers and ordered by price. That is the first thing a player fixes and the last thing this module knew about.
2. **Slice 3P — economy by host numbers (A2, A4, B3, C2, C5). Shipped 14 September 2026.** The economy candidates come from the host catalogue, the order comes from the production a level adds against the weighted price it costs, the queue time is a tie-break rather than an assumed discount, the persona keeps its identity through a seeded nudge the skill band bounds, and storage joins the same ranking with the fill-time trigger the guides describe. The algorithm, its constants and its acceptance evidence are in [`specs/gameplay-algorithms.md` E1–E3](specs/gameplay-algorithms.md#economy).

   Two things came out of building it. **The planner was refreshing resources and energy but not storage capacity**, so the storage rule was reading a stale column on a planet whose warehouse had just finished — the in-memory refresh now covers all three, which is what the host's own `update()` does. And **the bounded nudge is measurably smaller than the arithmetic**: on a planet with a position bonus the two cheapest mines came out 20% apart in payback, so the term can resolve a near-tie and cannot reorder anything else — which is the property the tests now assert instead of a flip that only exists on some planets.
3. **Host obligations — ten rules that live only in controllers (A3, B2, C3 and seven more).** The queue-upgrade predicate is the one this audit found; the capability map found nine others, including vacation mode blocking queue *additions*, the recall ownership check, and the expedition and fleet-speed bounds. R2 and R9 landed 15 September 2026 — the queue-upgrade predicate and the mission-required-ship answer — so the module no longer restates either. The remaining obligations (R3/R4/R5/R6/R7/R8) are decided and recorded in [`specs/host-change-request.md`](specs/host-change-request.md), none left open.

**The full sequence after 3P is set out in [`specs/gameplay-algorithms.md`](specs/gameplay-algorithms.md#delivery-order)**: routine and absence first (the host's own detector is the acceptance test), then research, units, saving, intelligence, raiding, colonies, social, identity and lifecycle — each step naming the algorithm sections it lands and the register gaps it closes.

Each slice lands with the module gate green — Rector, Pint, PHPStan level 8, the full Pest suite and
100% PCOV coverage — and this file is updated as findings close. A host obligation is not closed by a
module edit; it is closed by the host change, and the module is re-checked against the merged
revision.
