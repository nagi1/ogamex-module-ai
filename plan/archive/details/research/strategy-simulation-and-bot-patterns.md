# Experienced-player strategy and deterministic simulation

Research date: 14 September 2026. This document translates strong-player OGame
habits into a testable AI policy and offline simulator. It is not a guide to
automate official OGame accounts. Gameforge prohibits programs that interface
with the game and automate actions; public bot repositories are reviewed only as
engineering evidence for OGameX's in-product AI and test/replay simulator.[^rules]

The existing [decision policy](../specs/decision-policies.md) is still the
normative design. This research tightens it around player practice, a small
event-simulation boundary and a no-LLM dependency budget.

## Conclusion

Experienced players continuously solve a constrained planning problem, rather
than follow one universal build order:

1. Protect movable value before an absence or observed threat.
2. Convert resources into compounding capability using marginal return and
   current bottlenecks, not fixed mine ratios.
3. Buy fresh, legal intelligence before accepting combat risk.
4. Commit only when conservative net value survives fuel, loss, recycler,
   fleet-slot and timing costs.
5. Schedule the next decision at a material event, rather than polling.

A host-derived mechanics quote, the module's seeded utility scorer, pure
calculators and a discrete-event queue are sufficient. An LLM is not required
for state evolution, planning, simulation, validation or execution.

## Strategy translated into deterministic policy

| Recognisable player habit | Policy model | Hard constraint |
| --- | --- | --- |
| Fleet-save before an absence; vary routes/timing. | Enumerate legal mission × owned destination × speed combinations and score schedule fit, fuel, cargo protection and exposure. | No route is assumed safe because it is a moon, deployment or recall. Reject no-fuel, no-slot, no-cargo and no-return-window routes. |
| Fix energy, storage and queues; invest for long-run output. | Compare all host-offered actions by marginal horizon value and payback; retain fuel/resources/slots reserved for commitments. | Costs, requirements, production and modifiers come from a host quote, never object constants in the module. |
| Colonies improve production and fleet geography. | Score bounded legal slots by host-reported yield/field effects, travel graph, opportunity and risk. | Slot/class/lifeform effects are ruleset-versioned data, never a generic OGame rule. |
| Spy close to attack and react to activity. | Keep legal report fields with source, time, expiry and field-specific confidence; compare probe information value to probe risk/time. | Unknown/stale defence or resources can never mean zero/current. |
| Raid for net survivable return, not visible loot. | Evaluate a small role-template frontier with combat/travel quotes and 2–5 evidence-compatible defender scenarios. | Legality, fresh intel, fuel, cargo, recycler timing, slots and conservative profit all gate action. |
| Defence deters weak raids, but does not replace saving. | Include target-specific deterrence/recovery value in combat scoring. | Valuable movable fleets still receive a save candidate during an absence/risk. |
| Moons, phalanx, jumpgates and ACS change timing. | Publish coverage, cooldown, mission semantics, arrival windows and permissions as explicit capabilities. | Unsupported mechanics create no candidate and reveal no secret state. |
| Expeditions are bounded risky income. | Estimate from ruleset-specific own outcomes and loss-tail risk with seeded samples only when decision value justifies it. | No future hidden outcome, and profile loss/slot budgets apply. |

Gameforge's community-origin tutorials support these habits for fleet saving,[^save]
economy,[^economy] colonisation,[^colony] espionage,[^spy] raids,[^raid] defence,[^defence]
moons,[^moon] ACS[^acs] and expeditions.[^expo] They inform policy, but OGameX's
host is always the authority for actual mechanics.

## The smallest viable strategic architecture

Use a utility-selected, HTN-shaped discrete-event planner—not an LLM, a generic
GOAP framework or a behaviour-tree library:

* An ordered reactive guard handles disabled execution, incoming risk,
  commitment deadlines, fleet/queue events and forecast storage overflow.
* The existing `DecisionEngine`, `UtilityScorer` and `SeededRandomSource` decide
  which legal candidate matters now, with stable replay and near-tie variation.
* A few generic HTN-like methods complete multi-step goals: `ProtectAssets`,
  `GrowCapability` and `ExploitIntel`. They use semantic host capabilities, not
  static names for buildings, ships or missions.
* A pure forward forecaster provides event timing and resource/slot consequences;
  it never competes with core production or combat truth.

**Reconciliation after the deeper pass.** Read the paragraph above as a description of *information and
goals*, not as new machinery to build. The host survey found that every input these names describe is
already available from host services — prices, raw production, requirement-with-queue, storage capacity,
position bonuses, fleet fuel/distance/duration/slots — so `GameConfigSnapshot`, `StrategicState` and
`IntelBelief` are *views* the perception layer already assembles, not three new classes, and
`ProtectAssets` / `GrowCapability` / `ExploitIntel` are goal labels over the existing candidate set rather
than an HTN planner. Gate 2 forbids the alternative: a second data model beside `PerceptionSnapshot`, or a
planner over it, would be two authorities for the same facts. The concrete algorithms are in
[the gameplay algorithms](../specs/gameplay-algorithms.md).

### Inputs and candidate previews

`GameConfigSnapshot` is a versioned, host-published view of object descriptors,
requirements, costs, production, travel/fuel/cargo, combat engine version,
queues/slots, missions, visibility, protections and enabled modifiers.
`StrategicState` is the account's legal projection: own resources/rates, queues,
fleets, fuel, slot claims, commitments and routine window. `IntelBelief` only
contains legal reports/received data with `{source, observedAt, expiry, confidence}`;
there is no omniscient opponent model.

The existing `PerceptionSnapshot` is a sound narrow boundary: extend it only by
whitelist-mapped, host-published data. Include ruleset/config version and source
timestamps in every input hash and decision trace.

Every action is a preview—not a command—with actor, capability, parameters,
expiry, observation/config version, host cost/time quote, slot claims, constraint
failures and features. Candidate families are `wait`, `fleet_save`, `recall`,
`build`, `research`, `queue_units`, `spy`, `raid`, `recycle`, `transport`,
`colonise`, `expedition` and later `ACS`. A family without a proven ordinary
executor remains a recorded intent.

```text
reconcile own state and commitments
  -> emergency/legality/resource/slot/fuel gates
  -> generate a bounded candidate set from host capabilities
  -> cheaply forecast each legal candidate
  -> refine only top K / near ties with scenarios or combat estimation
  -> score, retain goal unless switch margin is exceeded, seeded near-tie choice
  -> revalidate in the normal host transaction
  -> receipt/trace/outcome and next meaningful due event
```

Use account-normalised components:

`utility = goal progress + discounted economic gain + safe expected profit + information value + schedule fit - loss risk - fuel/recycler cost - queue/slot opportunity - exposure - broken commitment - attention cost`.

Weights/thresholds are versioned persona policy. All terms, assumptions and
rejections belong in the trace. A simulated battle seed estimates uncertainty;
it is never the authoritative future-combat seed.

### Pure evaluators and the event simulator

| Evaluator | Small deterministic algorithm |
| --- | --- |
| Economy | Host cost/production quote over a bounded horizon: incremental value, queue delay, energy/storage loss and payback. |
| Prerequisites | Bounded dependency DAG using host requirements; return acquire/wait/queue steps instead of free search. |
| Fleet-save | Enumerate legal routes; gate schedule/fuel/cargo, then rank exposure and opportunity cost. |
| Intel | Field-specific time decay plus activity belief updated only by legal observed activity, recalls and committed outcomes. |
| Raid | Role-template frontier, host battle/travel quote, bounded plausible report states and lower-tail net value. |
| Expedition | Seeded own-outcome samples, shrunk toward a prior with sparse evidence. |

A conservative raid threshold can be:

`P20(loot + recoverable debris - combat loss) - fuel - recycler cost - fleet-slot/time cost - risk premium > profile threshold`.

The estimator behind that threshold is deliberately bounded, and the second pass fixed its parameters
rather than leaving them to taste: at most **five** candidates (the fleet the account owns plus a few role
variants), **n = 50** simulations for screening with **one shared seed stream** so a difference is the
candidate's rather than the sampler's, **n = 200** on the winner before committing, a **wall-clock deadline
checked between candidate batches**, and a reported pair — the count of losing runs and a lower-quantile net
profit — rather than a mean. A single mean is not an acceptable output: the corpus contains a documented
case where a mean-profit reading inverted a 60 M decision. Note also that the seed is never the engine's
real seed: a simulated battle only estimates uncertainty, and the host's engine remains the authority
([T2](../specs/gameplay-algorithms.md#t2-the-estimator)).

The offline simulator takes a snapshot and emits hypothetical events; it has no
database, network or executor. Use a min-heap key `(at, eventTypePriority,
sequence)` so replay is deterministic. Advance known production analytically,
not minute by minute. Events are queue/research completion, fleet arrival/return,
known inbound deadline, storage threshold, availability boundary, report expiry
and the next session. Branch only at explicitly uncertain legal intel. Cache by
ruleset version, visible-state fingerprint, action payload and estimator version;
never share across actors or hidden observations.

## Lessons from bot code, without bot operation

Do not copy client login, scraping, CAPTCHA/proxy/rate-evasion or dispatch code.
The following are architectural observations only:

| Source | Useful in-product lesson (verified at source level) | Do not adopt |
| --- | --- | --- |
| [TBot](https://github.com/ogame-tbot/TBot) | `AutoFarmWorker` has an explicit target lifecycle with re-probe escalation at **×3** then **×9**, a report age of **180 min**, a loot floor of **1,000,000**, and **18** concurrent attack missions leaving one slot free. Its scheduler sleeps to the next arriving fleet plus a 20–50 s jitter, and its fleet save derives its duration from the attack (`inbound × 1.30`) or the sleep window, recalls at **half** that duration, leaves 200,000 deuterium behind, and has a real give-up branch. | Its live-game controller, its fixed build chains, its 199-entry object enum and its price table; its README itself says botting is forbidden. |
| [Cruiser](https://github.com/kweimann/cruiser) | One state snapshot per wakeup with forced invalidation after every mutation; hostile-fleet detection that ignores probe-only fleets; a reaction wake at **arrival − 120…180 s**; route enumeration over destination × speed with fuel subtracted before cargo is loaded; a bounded retry ladder of 5/10/15/30/60 s. | The external game client, and two defects: a backoff that ignores hostile events for up to 60 s, and a recall predicate documented as remaining-time but implemented as elapsed-time. |
| [PHPOgameBot](https://github.com/racinmat/PHPOgameBot) | Pending work grouped by the resource it contends for, with the next wake computed as the **max** of resource ETA (including in-flight), building slot, fleet and expedition slots and ship returns; storage auto-inserted as a prerequisite, cheapest first; a closed-form probe-count solver; projected resources from stale intel with an explicit age parameter. | Its head-of-line block (one unaffordable command freezes its whole group), its literal 21-column "defenceless" filter, and the absence of any fuel or travel-time arithmetic. |
| [Trilogi77/OgameBot](https://github.com/trilogi77/OgameBot) | Marginal payback in metal-equivalent hours against an **adaptive capped threshold** `min(168 h, 24 h × (1 + avg level/20))`; a per-resource **savings reserve** netted against production; storage at 0.90 of capacity with a 0.50 floor; the only single-build guard that survives a stale read (live flag plus cached finish epoch). | Its two literal start orders (~40 and ~19 steps), its cost and prerequisite tables, and its object-name string literals. |
| [ogame-fleet-optimizer](https://github.com/peterradzisz/ogame-fleet-optimizer) | Paired seeds per generation, a screening→confirmation ladder (10 → 50 → 100, validate at 200–1,000), a wall-clock deadline checked mid-batch, and a published nearest-rank percentile method. | Anything other than offline benchmarking, its hardcoded counter map, and its `-inf` win-probability gate; OGameX core combat stays authoritative. |
| [klaasvp/trashsim-public](https://github.com/klaasvp/trashsim-public) | `N` full simulations with fresh state per run and per-run records, aggregated upstream — and the documented case where a mean-profit reading inverted a 60 M decision. | Reporting a mean as the answer. |

The common reusable pattern is state snapshot -> threat priority -> pure mechanics
calculation -> finite reservation -> idempotent/reconcilable work -> event-based
scheduling -> audit trail. OGameX already has safer equivalents: legal host
observations, ordinary execution, leases/receipts and decision traces.

## Additive delivery plan and acceptance evidence

1. **Capability preview:** host-publish a versioned side-effect-free legal action
   descriptor/quote, starting with executable building actions. Prove that a
   host-added object can participate without module-side object IDs.
   *Host status: no read-only action-quote entry point exists today, and the battle engine in particular has
   neither a seed nor a dry run, so this is a host change rather than a module slice*
   ([capability map](host-capability-map.md)).
2. **Safety/event state:** publish own fleet/slot/fuel/queue deadlines and legal
   alerts. Add pure save-route/next-due logic. Cover no fuel, no slot, no route,
   valid route and late-alert fixtures.
   *Host status: fuel, distance, duration, slots and arrivals all exist and are quoted per fleet; the
   "incoming fleet intel" service is a redactor, so the module assembles its own inbound picture*
   ([V2](../specs/gameplay-algorithms.md#v2-the-reaction-window)).
3. **Economic frontier:** generate quoted build/research/unit/colony candidates;
   rank marginal value with energy, storage, queues and reserves.
   *Host status: every input exists — prices, raw production with `force_factor`, build times,
   requirement-with-queue, storage capacity and position bonuses.*
4. **Intel/raid evaluator:** persist report fingerprints/confidence; add bounded
   compositions and lower-tail host-engine value. Leave raids recorded until the
   ordinary executor proves integration and revalidation.
   *Host status: blocked on the seedless, side-effecting battle entry point above.*
5. **Offline replay corpus:** virtual-clock cases for safe absence, overflow,
   fuel-starved save, fresh profitable raid, stale rejection, recovery and
   disabled capability. Same seed + snapshot must give byte-stable trace/intent.
6. **Calibration:** compare forecasts to committed outcomes by ruleset/estimator
   version; tune reviewed fixtures offline, never a self-modifying live policy.

Measure fleet-save feasibility/success, value exposed during absence, production
lost to energy/storage errors, raid calibration, recovery time, replay stability,
decision latency and simulation/DB cost. Use synthetic or disclosed-pilot data.

## LLM boundary and dependency budget

Default dependencies are the existing PHP/Laravel module, database/queue, host
mechanics/action adapters and optional seeded sampling. No model provider,
embedding/vector database, sidecar, GPU, agent framework or planner library is
needed for the strategic loop.

A model may only translate an explicit player preference into reviewed weights or
render a sanitised post-decision explanation. It cannot see secret state, choose
or execute actions, create commitments, change policy/version or block safety.
The deterministic path stays usable when no provider exists.

## Sources

[^rules]: [OGame rules](https://en.ogame.gameforge.com/ajax/main/rules), section 6; [Gameforge terms](https://agbserver.gameforge.com/files/pdf/en_GB/general_terms_of_use_en.pdf), section 6.
[^save]: [Guide 06: Fleetsaving](https://board.origin.ogame.gameforge.com/index.php/Thread/7915-Guide-06-Fleetsaving-Guide/) and [Tutorial 06: Saving](https://board.origin.ogame.gameforge.com/index.php/Thread/614-Tutorial-06-Saving/).
[^economy]: [Tutorial 01: Basic economy](https://board.origin.ogame.gameforge.com/index.php/Thread/591-Tutorial-01-Basic-economy/) and [OptiMine ROI Advisor](https://forum.origin.ogame.gameforge.com/forum/thread/314-optimine-roi-advisor-for-miners/).
[^colony]: [Tutorial 13: Colonisation](https://board.origin.ogame.gameforge.com/index.php/Thread/621-Tutorial-13-Colonisation/).
[^spy]: [Tutorial 08: Espionage](https://board.origin.ogame.gameforge.com/index.php/Thread/616-Tutorial-08-Espionage/).
[^raid]: [Tutorial 09: Raids](https://board.origin.ogame.gameforge.com/index.php/Thread/617-Tutorial-09-Raids/) and [Tactic 05a: Fleet composition](https://board.origin.ogame.gameforge.com/index.php/Thread/793-Tactic-05a-Fleet-composition/).
[^defence]: [Tutorial 03: Defense](https://board.origin.ogame.gameforge.com/index.php/Thread/593-Tutorial-03-Defense/).
[^moon]: [Tutorial 15: Moon](https://board.origin.ogame.gameforge.com/index.php/Thread/623-Tutorial-15-Moon/).
[^acs]: [Tutorial 10: ACS](https://board.origin.ogame.gameforge.com/index.php/Thread/618-Tutorial-10-ACS/) and [Guide 10: ACS](https://board.origin.ogame.gameforge.com/index.php/Thread/790-Guide-10-ACS-guide/).
[^expo]: [Tutorial 12: Expeditions](https://board.origin.ogame.gameforge.com/index.php/Thread/7793-Tutorial-12-Expeditions/).

Further inspection: [TBot AutoFarm](https://github.com/ogame-tbot/TBot/blob/master/TBot/Workers/AutoFarmWorker.cs), [TBot ROI](https://github.com/ogame-tbot/TBot/blob/master/TBot/Includes/CalculationService.cs), [TBot FleetScheduler](https://github.com/ogame-tbot/TBot/blob/master/TBot/Workers/FleetScheduler.cs), [Cruiser bot](https://github.com/kweimann/cruiser/blob/master/bot/bot.py), [Cruiser engine](https://github.com/kweimann/cruiser/blob/master/ogame/game/engine.py), [PHPOgameBot queue](https://github.com/racinmat/PHPOgameBot/blob/master/app/model/queue/QueueConsumer.php), [PHPOgameBot farms](https://github.com/racinmat/PHPOgameBot/blob/master/app/model/FarmsAttacker.php), [TrashSim thread](https://board.en.ogame.gameforge.com/index.php?postID=6837286&thread%2F771533-trashsim-ogame-combat-simulator%2F) and [OGame Tools simulator](https://forum.origin.ogame.gameforge.com/forum/thread/164-ogame-tools-combat-simulator/).
