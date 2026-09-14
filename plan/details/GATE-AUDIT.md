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
| A2 | `FirstBuildingTarget` names four object ids (1, 2, 3, 4) and is the entire set of economy buildings the account may choose, so a resource building a mod adds can never be built by it. | code-read | **Open.** Derive the economy candidates from the host catalogue — the objects the host itself reports as producing resources or storing them. |
| A3 | `AiBuildingMachineName` names `shipyard` and `nano_factory` to restate a game rule that the host enforces in its **building controller** (object ids 21 and 15) and again while it processes the queue — but in no service the module can ask. | code-read + host source | **Kept, recorded as a host obligation.** Deleting it would let the account upgrade a shipyard the game forbids it to upgrade: `BuildingQueueService::add()` accepts the request and the queue processor cancels it later, so the account would spend a queue slot and receive a receipt for an action that never happened. The module restates the host's two objects because the host publishes no predicate for the rule. The fix belongs in the host — a service-level "may this building be upgraded now" answer — and the module keeps the check until one exists. |
| A4 | `AiProfileSettings::BUILDING_WEIGHTS` is a per-profile map from object name to weight, read by the scoring policy and written by nothing in production, so the object names in it decide the build order. | code-read | **Open.** Dies with A2 once the order is computed from host numbers. |

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
| B1 | `energy_blocker` is a scored feature with a weight of 20 that no caller ever sets to anything but `0.0`, so twenty points of every action score are a constant and an energy signal that was meant to exist never arrived. | code-read | **Open.** Delete the feature and its weight; the account's energy need is enforced where the building is chosen instead. |
| B2 | `AiBuildingMachineName` is a two-case enum with one caller. | code-read | **Deliberate for now**, for the reason in A3: the enum exists because the host has no predicate for its own rule. It is two cases with one caller and it stays until the host owns the rule. |
| B3 | `FirstBuildingTarget`, `BuildingScoringPolicy`, its two implementations and a settings key exist to order four objects by a preference nothing provisions; the same order falls out of two host numbers — the production a level adds and the price it costs. | code-read | **Open.** One policy over host numbers instead of three layers over a table. |
| B4 | `ArchetypePolicyResolver`, `ContextBuilder`, `QueueAiBuilding` and `RunAiSession` are contracts with a single implementation. | code-read | **Deliberate.** These are the seam list the plan commits to — the host action gateway, the session runner and the model-backed context builder are exactly what a host change or a test must be able to replace, and two of them are already replaced in tests. A contract with no swap and no test override would be the defect this entry describes; none of these is that. |
| B5 | Nothing else was found: no cache, no optimisation without a measurement, and no layer that only forwards. The adapters call host services and return their results rather than wrapping them twice. | code-read | Clean. |

## Gate 3 — what a good professional OGame player does

| # | Finding | Evidence | Disposition |
| --- | --- | --- | --- |
| C1 | **The account ignores energy.** The host throttles a planet's whole production by the energy it can cover (`PlanetService::updateResourceProductionStatsInner` multiplies by the consumption ratio), so a planet with mines and no plant produces a fraction of what its mines say. Nothing in the module reads energy; the only mention of it is a persona weight on the solar plant, and A4 means that weight is never provisioned. No player plays this way — energy is the first thing they fix. | code-read + host source | **Open, highest severity.** Before anything else, a planet running a deficit wants capacity, chosen from the objects the host says produce energy. |
| C2 | The build order is arbitrary. With no provisioned weights the seeded policy scores the four targets as `100 + 0 + crc32(seed, target) % 10`, so each account has a fixed but meaningless order. It cannot be named as something a player does. | code-read | **Open.** Order the economy by what an experienced player compares: the production a level adds, against the price it costs. |
| C3 | The module refuses a shipyard or nanite-factory upgrade while units are building. Checked against the host: this is the host's own rule, stated in its building controller, so the refusal is correct play and wastes no action — but the module states it instead of asking (see A3). | code-read + host source | Correct behaviour; the duplicated knowledge is recorded as a host obligation in A3. |
| C4 | The behaviours checked and named: fleetsave before raiding, raids only from published reports, authored dialogue before any provider, an observation path that cannot write, delivery through the host's own chat, caps that stop work rather than discard it, and profile-off meaning no action at all. | code-read | Clean — each is either play a player performs or a mechanism invisible to one. |
| C5 | Storage capacity is not a build rule, so a planet whose resource caps out gains nothing from more mining and the account does not notice. A player builds storage. | code-read | **Deliberate for now, listed.** No capability depends on it yet; it belongs with the economy slice so it is not built twice. |

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
  production a level adds against the weighted price it costs, and compare **return per hour of queue**
  when two candidates are close, because a long build occupies the only slot the account has.
- **C5 (storage) is promoted from "deliberate for now" to planned**, since the sources agree on the
  trigger: upgrade storage when the time to fill the remaining capacity is shorter than the time until
  the next planned spend.

## Order of work

1. **Slice 3O — energy becomes a rule (C1, B1).** The dead `energy_blocker` feature is deleted, and a planet that cannot cover its consumption wants capacity before anything else, derived from the host's own production numbers and ordered by price. That is the first thing a player fixes and the last thing this module knew about.
2. **Slice 3P — economy by host numbers (A2, A4, B3, C2, C5).** The economy candidates come from the host catalogue, the order comes from the production a level adds against the weighted price it costs (and against the queue hours it occupies), the persona keeps its identity through the seeded variation and skill band that already exist, and storage joins the same ranking with the fill-time trigger the guides describe.
3. **Host obligation — the queue rule predicate (A3, B2, C3).** The host owns the rule that a shipyard or nanite factory may not be upgraded while units are being built; it should publish it as a service-level answer so the module can ask instead of naming objects. The module keeps its check until then.

Each slice lands with the module gate green — Rector, Pint, PHPStan level 8, the full Pest suite and
100% PCOV coverage — and this file is updated as findings close. A host obligation is not closed by a
module edit; it is closed by the host change, and the module is re-checked against the merged
revision.
