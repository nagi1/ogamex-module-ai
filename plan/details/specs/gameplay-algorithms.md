# The gameplay algorithms — execution strategy

Written 14 September 2026, after a deep source pass over the public automation corpus, the published
guides and the OGameX host code itself. It is the **execution strategy**: for every gap in the
[register](../GAP-REGISTER.md), the concrete algorithm that closes it, its inputs, its constants and
where those constants come from, how it fails, and what evidence accepts it.

[`decision-policies.md`](decision-policies.md) remains the normative description of *what* the module
decides. This file is the *how*: it names each rule, its arithmetic and its provenance. Where the two
differ, this file is the later reading and says so in [Corrections](#corrections-to-earlier-docs).

The three [cognition gates](cognition-gates.md) are the acceptance criteria for every block below, not
a preamble: gate 1 means the object universe is read, never written, gate 2 means the smallest
mechanism is chosen and measured before it is optimised, gate 3 means the mechanism is namable as
ordinary experienced play.

Provenance markers used throughout: **host** (verified in OGameX source), **verified** (read in a
project's source), **documented** (guide, wiki or official rules), **contested** (sources disagree),
**placeholder** (no source exists and the number must come from our own telemetry), **synthesis** (our
combination of the above, stated as such).

## Gap → algorithm index

Use this when closing a gap: the section named here is the algorithm, and the section's *Host inputs*
line is the list of host answers it needs.

| Gap | Algorithm | Mechanism in one line | Status |
| --- | --- | --- | --- |
| A2, A4, B3, C2, C5 (gate audit) | [E1](#e1-payback-ordering--the-next-mine) [E3](#e3-storage--the-fill-time-trigger) | Order the economy by production added over weighted price paid; storage by time-to-fill against time-to-spend | **shipped** |
| C1 | [Y1](#y1-the-energy-interlock) | Capacity before the level that would outdraw the planet (shipped) | **shipped** |
| G2 | [R1](#r1-the-research-hurdle) [R2](#r2-capability-research) | Research only when it out-pays the last purchase, and only when it unlocks something | **shipped** (executor + chain/capability research; R1 hurdle still rides the shared payback path) |
| G3, A2, G15 | [U1](#u1-role-derivation--what-units-are-for) [U2](#u2-cargo-sizing) [U3](#u3-defence--unprofitability-not-ratios) | Roles from host unit properties; cargo sized from host capacity; defence only when attacked | **U1/U2/U3 shipped** (cargo, colony ship, probe, escort, defence); the two intel roles read the host's own attack and report answers |
| G4, G8, G9 | [V1](#v1-the-save-state-machine) [V2](#v2-the-reaction-window) [V3](#v3-the-save-that-fails) | Save to a duration derived from the absence, react inside a window, and let one fail | **V1 and V3 shipped** (deploy between own planets under attack; a named skip at the blessed placeholder rate); V2 deferred to the capacity runs |
| G5 | [N1](#n1-probe-sizing) [N2](#n2-target-lifecycle) | Probe enough to reveal, escalate when it does not, and let stale targets die | **N1/N2 shipped** (one probe, legal target, reports re-published as intel; 24 h staleness) |
| G6, S4 | [T1](#t1-the-profit-test-as-an-audit-trail) [T2](#t2-the-estimator) [T3](#t3-the-bashing-limit) | Loot − deuterium − expected losses must clear a tail threshold | **T1/T2/T3 shipped** (P20 estimate, bashing limit, profit test, attack dispatch); S4 report-sharing still open |
| G7 | [CL1](#cl1--slot-choice) [CL2](#cl2--the-colony-is-another-mine) | Choose the slot by the host's own position bonuses; treat the colony as a mine | **shipped** (executor + colony-ship role); CL2 treat-colony-as-mine is the next economy step |
| G10, G11 | [H1](#h1-the-active-hours-constraint) [H2](#h2-session-shape) [H3](#h3-absence) | A real dark period under the host's own detector threshold, heavy-tailed sessions, planned absences | **shipped** |
| G12, S1–S3 | [SOC1](#soc1--speaking-first) [SOC2](#soc2--alliance-life) | Initiate rarely and in context; answer the alliance | **deferred to Package 6 (scope decision)**; reaction answering stays shipped |
| G17 | [X1](#x1-transfers-between-own-planets) [X2](#x2-trade) | Ferry with in-flight netting; there is no marketplace | **decided**: X2 closed by evidence (no marketplace), X1 trigger fixed, executor next slice |
| G18 | [SOC2](#soc2--alliance-life) | Apply, then behave like a member | **scope decided**: not in Package 4 |
| O1–O6 | [L1](#l1-retention) [L2](#l2-account-states) [H6](#h6--suspension-gate) | Enforce retention, name the states, ask before waking | **L1, L2, H6 shipped** |
| I1–I8 | [P1](#p1-provisioning-identity) | Plausible identity, uncorrelated seeds, staggered arrival | **I1–I8 shipped** (I7 decided, I8 class provisioned) |
| A1, A3–A5 (register wave 5) | [AG1](#ag1--per-account-divergence) [AG2](#ag2--the-growth-curve-is-ours-to-record) [AG3](#ag3--request-and-activity-footprint) | Diverge by construction; record our own curve; decide the last-activity stamp deliberately | **AG1/AG2/AG3 shipped**; A4 decided (correct signal 8) and AG4 closes it |
| W6-1 | [T6](#t6-the-raid-target-score) [T7](#t7-tiered-profit-gate-and-cargo-sizing) [T8](#t8-launch-time-re-check) | Activity risk, per-type intel decay, fuel + slot cost, tiered profit gate, cargo sizing, dispatch re-check | **partially shipped** (fuel + tiered gate, cargo-capped loot, dispatch re-check, travel cost + the score-ratio pre-filter + the storage-fill raid schedule + proximity clustering; relationship and contest deferred — see T6) |
| W6-2 | [N4](#n4-spy-target-prioritisation) [N5](#n5-own-signature-control) | Score spy targets (distance/novelty/yield) instead of id-order first-fit; dispatch and disappear | **shipped** (scored target choice + active-target skip; dispatch-and-disappear already held) |
| W6-3 | [V6](#v6-the-proactive-save) [V7](#v7-variation-route-scoring-and-dispatch-masking) [V8](#v8-shadow-waves) | Proactive offline-gap save, route × speed scoring, dispatch masking, shadow waves | **shipped** (farthest-destination scoring + cargo lift + the proactive offline-gap save + the two-body shadow split; route × speed resolved for deployment saves — see V7) |
| W6-4 | [U5](#u5-stage-composition--the-production-stock) [U6](#u6-the-launch-subset--counters-not-the-whole-stock) | Stage-sized production stock (fodder, workhorse, recycler, cargo), launch as the counter-selected subset, simulated before dispatch | **partially shipped** (payload-sized cargo in `QueueableUnitPlanner`; stage workhorse and recycler sizing deferred) |
| W6-5 | [F1](#f1-phalanx-coverage) [F2](#f2-deploy-recall-interception) [F3](#f3-the-moon-as-geography) [F4](#f4-the-recycle-trip) [F5](#f5-blind-lanx-via-the-debris-field) [F6](#f6-moon-destruction) | Phalanx, deploy-recall, moon geography, recycle trip, blind lanx, moon destruction — host-supported, module-unwired | **partially shipped** (F2 recall executor + F3 moon-destination save; F1/F4 ride the deferred crash executor, F5 P2, F6 last-priority) |
| Ninja (pass-6) | [NN1](#nn1-anti-ninja-checks-on-the-raid-path) [NN2](#nn2-the-ninja-trap-defender-counter-crash) | Anti-ninja staging checks on the raid path; the defender's timed counter-landing | **partially shipped** (NN1 moon-staging check at dispatch; NN2 trap deferred — gated behind a reviewed cluster) |
| Expeditions (pass-6) | [EX1](#ex1-slot-16-outcomes-and-never-a-save) | Slot-16 only, host-returned outcomes, never a fleetsave | **shipped** (slot-16 executor over the host mission, one disposable civil cargo ship; host surface verified — DISC-004 closed) |
| W7-1 / W7-2 (economy) | [E6](#e6-spend-a-windfall-before-warehousing-it) | Spend a surplus before warehousing it | **shipped** — hypothesis (b) then (a): a full warehouse is a spend signal, and a severe scarcity makes a mine outrank the ship habit |

## How to read an algorithm block

```
Gaps:      which register entries it closes
Host:      the host answers it reads (see ../research/host-capability-map.md for the exact methods)
Rule:      the algorithm, with real constants
Constants: where each number comes from — persona policy, published play, or the host
Gate:      why it is not a hardcoded AI, not over-engineered, and namable as play
Evidence:  what backs it
Accept:    the observation that proves it works
```

---

## Shared spine

These six mechanisms are used by everything below; they are written once so no later block restates
them.

### SP1 — One bounded candidate set per decision

Every decision builds a small list of legal candidates from **host-quoted** facts (price, build time,
production, requirement graph, unit properties, mission catalogue, queue and slot state), scores them,
and takes one. There is no search over subsets, no planner and no solver: the corpus converged on
lists and ratios, the [techniques note](decision-techniques.md) measured the alternatives as worse,
and gate 2 forbids the machinery.

### SP2 — The score is normalised against the account, not against absolute numbers

`utility = goal progress + discounted economic gain + expected profit + information value + schedule fit
− loss risk − fuel and recycler cost − queue and slot opportunity − exposure − broken commitment −
attention cost`, with every term expressed per account-day (a million resources is a different decision
for a three-week account than for a two-year one). This is the shape the plan already commits to.

### SP3 — Wake at the next material event, never on a fixed period

**Verified** in three independent projects and it is the single most valuable scheduling idea in the
corpus: compute the next moment at which something could change and sleep until then.

```
next_wake = min over open work of:
    resource_eta(cheapest ambition)         # missing resources / production per second
    building_finish, research_finish        # the queue countdown
    fleet_arrival, fleet_return             # including an inbound attack's real ETA
    slot_free, storage_threshold, session_window_start
plus a heavy-tailed jitter
```

PHPOgameBot computes exactly `max(now, resource_eta, build_slot_free, fleet_slot_free, ship_return)`
(**verified**); TBot computes `min(production_time, transport_arrival, returning_expedition) + jitter(SomeSeconds)`
(**verified**); Cruiser pushes an absolute wake at `hostile_arrival − randint(120, 180)` s
(**verified**). The three agree, so this is not taste — it is the mechanism. The jitter must be
heavy-tailed, not uniform: see [H2](#h2-session-shape) and the traps in [H5](#h5-what-makes-a-schedule-look-worse).

### SP4 — One mutating action at a time per contended resource

A planet has one building slot, a player has one research slot and a bounded number of fleet slots.
The corpus's cleanest expression is PHPOgameBot's dependency typing: pending work is grouped by the
thing it contends for and one head per group runs (**verified**) — but its `break` on failure is a
defect we do not copy, because one unaffordable entry freezes its whole group. Our form: skip an
unaffordable candidate rather than blocking on it, and keep the account's own queue-occupancy guard
(*trilogi77* backs a live "is something building" flag with a cached finish time so a stale read cannot
double-enqueue — **verified**, and it is the only project that defends against that).

### SP5 — Reservation before spending

**Shipped as `ReserveFloor`** — the build and research affordability gates now require the price plus
a per-resource floor, and a resource the price does not spend keeps no floor (so a deuterium reserve
never freezes surplus metal and crystal).

A build that consumes the last resources will prevent the next save, the next commitment and the next
research step. Reserve first: a per-resource floor that survives the purchase, reduced by the
production expected to arrive during the wait, so "saving deuterium for a drive" does not freeze the
surplus metal and crystal (*trilogi77*'s `savings_reserve` — **verified**, and the most elegant
mechanism in the corpus). `keep_resources_buffer 0.10`, `max_saving_hours_economy 4.0`,
`max_saving_hours_research 6.0` are its published defaults.

### SP6 — Record the decision, its rejected candidates and the next due event

Already module policy. What the corpus adds: the record must name the **estimator version** and the
**observation age** (see [T2](#t2-the-estimator)), so a calibration pass can tell a policy change from
an input change.

### SP7 — The activity and intel reader

**Shipped as `ActivityIntelReader`** (read-only; published from `PlayerObservationService::targetReports()`
as per-report `confidence` and `activity`).

Derived signals are computed once and consumed by the tactical planners, never re-derived per
planner. The host gives every body a `Planet.time_last_update` — the 15-minute activity marker, set
by any planet-context action and nothing else (**host**) — and a galaxy activity status
(`GalaxyController::getPlanetActivityStatus`). One read-only reader publishes, per body:

- `activity_at(body)` — was the body touched within its 15-minute window;
- `moon_only_activity` — a touched moon with an untouched planet signals moon facilities in use
  (phalanx / jump gate) while a fleet is in transit (INT-006);
- `intel_confidence(report, type)` — per-type freshness decay: resources and fleet go stale fast,
  coordinates and past losses slowly; a report older than the persona band supports a probe or a
  rejection but never an attack (INT-004, RAID-005; [N3](#n3-freshness-and-refusing-to-guess));
- `activity_probability_at_eta` — the target being online when the fleet arrives, the raid-risk
  input (RAID-004).

The reader is host-quoted and never writes: the module neither fabricates activity nor hides it,
and the marker is refreshed only through real work, exactly as
[H4](#h4-the-activity-marker-is-a-side-effect-never-a-ping) requires.

---

## Economy

### E1 — Payback ordering — the next mine

**Shipped in slice 3P** as `EconomyUpgrades`.

**Gaps:** A2, A4, B3, C2 (gate audit) · **Host:** the objects the host reports as producing resources
(`getGameObjectsWithProduction()`), each one's price for the next level, its build time, and its raw
production at `level` and `level + 1` (the raw form matters: the host scales production by the planet's
current energy factor unless asked otherwise — **host**).

```
for each production object the building queue accepts:
    cost  = host price(next level) in the accepted trade band
    gain  = host raw production(level + 1) − raw production(level), per hour
    score = gain / cost
take the best affordable; stop when the best payback exceeds the persona's horizon
```

**Constants.** The trade band is published play: `M + 1.5 C + 2 D` (halfguru — **verified**), and the
guides' `3:2:1` and `2:1:1` are the same idea in words (**documented**). The stop rule is published
play too: a horizon of 2–3 real days at any speed (**documented**), expressed as an adaptive cap by
*trilogi77*: `threshold = min(168 h, 24 h × (1 + average mine level / 20))` (**verified**). The
per-account spread comes from the persona's skill band and the seeded variation, which already exist.

**Gate.** Candidate list and every number come from the host (gate 1); it is one sort key, and it
deletes `FirstBuildingTarget`, `BuildingScoringPolicy`, both of its implementations and
`AiProfileSettings::BUILDING_WEIGHTS` (gate 2); and it is the rule veterans state in their own words,
"prioritize mines with lowest amortization first" (**documented**), which gate 3 asks for.

**Evidence.** Regression: the opening a fresh funded account produces must be the published opening
(solar plant first, metal ahead of crystal, crystal ahead of deuterium) without any of those names
appearing in module code.

**What the slice measured.** On a planet with a position bonus the two cheapest mines came out **20%
apart** in payback, so the persona's nudge — bounded at 4% — can resolve a near-tie and cannot reorder
anything else. The tests therefore assert what is true on any planet (the same set of host objects, a
reproducible order, and no candidate appearing or disappearing) rather than a flip that only exists
where two upgrades happen to be close.

**Accept.** Two accounts with different skill bands produce different-but-defensible orders from the
same host data, and adding an object to the host makes it a candidate with no module edit.

### E2 — Queue occupancy: honest about what is not proven

**Gaps:** C2, B3 (gate audit) · **Host:** build time for the next level (the host quotes it; the queue-occupancy
guard reads the live queue).

**Finding.** Not one of the eleven surveyed projects discounts payback by the queue time a build
occupies. Five read the queue, but only as a boolean "is something building" guard; *halfguru* computes
the construction time and never feeds it back into its ordering key (**verified**). So
"return per hour of queue" would be a mechanism with no precedent, which is exactly what gate 2 asks
us to justify rather than assume.

**Rule.** Payback orders the candidates. Queue time is used twice and only twice: as the **tie-break**
between candidates whose paybacks are within the persona's switch margin, and as an **input to the next
wake time** (a 6-hour upgrade is a reason to sleep, not a reason to reject a good upgrade). A long build
must never be chosen while a short one with a better hourly return is available *only* when the persona
is about to be absent for less than the long build's duration.

**Gate.** It is the smallest form that keeps the account from starting a six-hour upgrade twenty minutes
before it wants to spend; adding a per-hour discount would be an unmeasured optimisation.

**Accept.** A measured comparison in the pilot: payback order versus payback × queue-hour, same seed,
same account, four weeks. Until that exists, the tie-break stands and the metric is not claimed.

### E3 — Storage — the fill-time trigger

**Shipped in slice 3P.**

**Gaps:** C5 (gate audit) · **Host:** current storage capacity per resource, current stored amount, the object
enumeration that reports storage (`getBuildingObjectsWithStorage()` — note it excludes stations, so a
mod-added station with storage is invisible; recorded as a host obligation).

**Rule.**

```
for each resource:
    remaining = capacity − stored
    fill_time = remaining / max(production_per_hour, ε)
    if fill_time < the storage horizon (48 h, from the guides' "hold 24–48 hours of production"):
        queue the storage object that raises that resource's capacity, soonest to fill first
```

The shipped form keeps the guides' horizon as a named constant rather than the "time until the next
planned spend", because the absence model that would supply the second number is [H3](#h3-absence) and
is not built yet; when it lands, the horizon becomes the absence length and the constant disappears.

**Constants.** The guides state the trigger in words — storage "should hold at least 24–48 hours of mine
production", and "always check your storage before logging off for a long period" (**documented**). The
corpus implements it as a threshold, and disagrees on the number: 0.8 of capacity (halfguru), 0.9 with a
0.5 floor below a minimum-capacity target (trilogi77), at-capacity (ogame-ninja) (**all verified**).
Guide-based fill-time beats all three, and the numeric thresholds become persona flavour.

**Gate.** Capacity and production are host answers; one comparison, no new layer; and it is the rule
players state, not one we invented. Overflow is not cosmetic: above capacity the surplus is fully
lootable (**documented**), so a full warehouse is a gift. Two invariants the slice found by testing:
capacity and production per hour are **stored columns**, so a planning pass must recompute them in
memory (`updateResourceProductionStats`, `updateResourceStorageStats`) or it judges a planet on the
warehouse it had before its last build; and they are **throttled by the energy factor**, so on a planet
that cannot cover its mines nothing ever fills and storage is correctly not offered — which is also why
this rule only makes sense after [Y1](#y1-the-energy-interlock).

**Accept.** A planet left alone with a filling warehouse queues storage before the projected overflow,
and a planet with an empty warehouse does not.

### E6 — Spend a windfall before warehousing it

**Shipped** — hypothesis (b): a full warehouse is a spend signal, not a warehouse signal (16 September 2026).

**Gaps:** W7-1, W7-2 · **Host:** current storage capacity and stored amount per resource, production
per hour (the same stored columns E3 reads), and the production objects' payback (E1).

**Finding (measured).** The grand run's two fleeters finished 30× behind the leading miner with ~880k
and 1.8M metal against ~1.5k crystal, and had queued `storage:metal_store` 15× and
`storage:crystal_store` 10×. A raid windfall lands in one tick and fills the warehouse instantly; E3's
fill-time trigger read that instant fill as "about to overflow" and queued a bigger warehouse, which the
next windfall filled again — a ratchet that left the account warehousing loot instead of spending it.
The fleeter's `FleeterPolicy` also carries no `Build` preference, so its sessions rank ships 15 points
above mines and the loot is never reinvested.

**Rule.**

```
a warehouse upgrade is queued only while its fill lies in the future:
    0 < time_to_fill < absence
an already-full warehouse (time_to_fill = 0) is a spend signal, not a warehouse signal:
    the surplus is answered by E1 / Y1 / R1, never by a bigger warehouse
```

**Constants.** The split "future fill → warehouse, present overflow → spend" is the corpus's own
distinction: the guides say storage "should hold 24–48 hours of production" and "always check your
storage before logging off" (a future fill), while *ogame-ninja* grows storage only "at capacity" and
every veteran spends a surplus before buying storage (**documented**). No new constant: the threshold
is zero and the horizon is E3's.

**Gate.** One comparison, no new layer, no new host input (gate 2); the numbers are all the host's
(gate 1); and the rule players state in their own words, "a player spends a surplus before warehousing
it" (gate 3).

**What shipped (16 September 2026).** Hypothesis (b): `EconomyUpgrades::storage` now skips a full
warehouse (`time_to_fill <= 0`), so a present overflow is answered by the routine economy — the mine
that spends the surplus — and only a fill that lies in the future (`0 < time_to_fill < absence`)
queues a bigger store. The two chain-precedence tests are re-pinned: a full warehouse falls through to
the chain or a mine, and a near-full warehouse on one planet still preempts a routine step on another.
Hypothesis (a) then shipped (IMPL-025): `CandidateActionFactory` boosts the Build candidate's
`resource_need` by a bounded scarcity term — 0 below a 10:1 abundant-to-scarce ratio, 1.0 at 1,000:1 —
so a fleeter's mine outranks its ship habit only when one resource is genuinely the binding constraint,
and a safety action (a fleetsave under a visible raid) is never outranked.

**Accept.** A planet whose warehouse is full queues the mine that spends the surplus, and a planet
whose warehouse will fill during the absence queues the warehouse — measured on a frozen-clock replay
before it touches the holy universe.

### E4 — Ferrying resources between own planets

**Shipped in `QueueableTransferPlanner`** (X1).

**Gaps:** G17, A1 · **Host:** `TransportMission` (own planets are legal), the fleet's total cargo
capacity, the fleet-save margin, and the queue state of both planets.

```
need(resource) = max(target_level_cost(resource) − on_planet − in_flight_to_this_planet, 0)
send only if need exceeds the persona's minimum shipment and the source keeps its S5 reserve
```

**Finding.** The cleanest formulation in the corpus nets off **in-flight** resources rather than
on-hand alone (ogame-infinity: `need = max(target − onHand − inFlight, 0)`, with each mission deciding
whether it debits the origin, credits the destination, or neither — **verified**). Without that netting,
two shipments are dispatched for one hole. *r4fek* transports only when the empire total covers the
cost and skips shipments below 50,000 combined metal and crystal (**verified**).

**Gate.** It is what any player with two planets does; the numbers are persona policy; the capacity and
fuel come from host quotes.

**Accept.** Two queued transports cannot be sent to fund one building level.

### E5 — The opening, and where taste is allowed

The published opening is a step table, not a rule, and the sources diverge after roughly ten steps
(**documented**, and *contested* — Sidian's research entry point differs from OGames'). The module keeps
**no** step table. What it keeps is the payback rule (E1), the energy interlock (Y1) and a persona's
**taste** expressed as a band: how long a payback the account will accept, how far behind the metal mine
it lets the crystal mine fall, and how much crystal it is willing to hold. Published offsets exist
(`crystal = metal − 3`, `deuterium = metal − 7` in pyogame; `−2 / −5` in r4fek — **both verified**) and
they disagree, which is the point: offsets are preference, payback is not.

---

## Energy

### Y1 — The energy interlock

**Gaps:** C1 (gate audit) · **Host:** the planet's energy balance (a stored column needing a refresh call), the
energy each object produces at the next level (raw, unscaled), and the object catalogue.

```
if the planet's energy balance is negative: build the cheapest capacity the host offers
for each production object: if balance + gain(next level) < 0: build capacity first
```

**Shipped** as slice 3O. Deliberately *predictive*: the guides are split on purpose-built deficits
(one guide prints "−6 but improvement, produce at 90%" as acceptable; the other says plan one or two mine
levels ahead — **documented and contested**), and the module takes the planning form because an
unrepaired deficit is the one thing ordinary play never looks like.

**Accept.** A fresh funded account queues `energy:*` before the mine that would outdraw it, and a planet
that covers its next upgrade never does.

### Y2 — Fusion, satellites and where the sources give up

Both alternatives are conditional and the conditions are the host's: fusion needs energy technology 3
and a deuterium synthesizer 5 (**documented**, and the objects' own requirement graph already says so),
and satellites are units — destructible, unrepairable, better on cold planets (**documented**). The
switch point, by contrast, is *contested* in the strongest form: 16, 18, 20–26, "high twenties", 30 and
32 all appear, each with a calculation attached. **Rule:** the switch is a persona band over the
host-quoted cost of the next plant level against the host-quoted output of the satellites it would take
to match it, and the module never asserts a canonical level. Fusion appears only when deuterium is a
surplus, which is what the sources actually agree on.

---

## Research

### R1 — The research hurdle

**Gaps:** G2 · **Host:** the research queue (`ResearchQueueService::add`, with the affordability
pre-check the service omits), the requirement graph (`objectRequirementsMetWithQueue`), prices, research
time, and the last purchase's payback.

**Finding.** TBot's rule is the best in the corpus and it is one comparison: a research is taken only
when its payback beats the payback of the last mine actually started (`lastDOIR`), capped at the
persona's horizon; otherwise the account keeps mining (**verified**). It is namable as play — "do not
research what pays back slower than the mine you just built" — and it needs no per-object cap table,
which is what the same project's 16 research names and 14 caps are.

**Rule.**

```
hurdle = payback of the last economy purchase (14-day decay, so an old purchase stops gating)
for each research the host says is available and affordable:
    if payback(research) <= min(hurdle, persona horizon): queue the best
otherwise: mine (E1)
```

**Gate.** One number carried forward; no cap table; the availability answer is the host's own graph.

**Accept.** An account that just built a cheap mine does not immediately queue an expensive research,
and an account whose last purchase is stale returns to mining.

### R2 — Capability research

**Gaps:** G2, G7, G3 · **Host:** the same requirement graph.

**Rule.** A research with no income of its own scores through what it unlocks — the **capability** it
makes reachable, priced by the cheapest object that capability then allows. This is what TBot does for
astrophysics and, in a derived form, for drives and the research lab (**verified**), and it is what the
guides describe for astrophysics, "treat them like they are mines — build whatever has the shortest
return on investment" (**documented**). The mine-level↔astrophysics roadmap in the miner guide is a
*relation between two things the host prices*, so it is a derived consequence, not a table.

**Gate.** Nothing names astrophysics or a drive; the numbers come from the host graph, so a mod-added
technology that unlocks something is scored the same way.

**Accept.** A colony becomes reachable because the account computed it, not because a name is listed;
and the same code scores a mod-added technology.

### R3 — Saving for an expensive step

**Gaps:** G2, SP5 · **Host:** resources, production, queue state.

**Rule.** When the chosen step is unaffordable, the account does not idle: it continues cheaper work
whose cost fits **outside** the reserved amount, and the reservation is reduced by the production
expected during the wait (*trilogi77* — **verified**). The wake time is the resource ETA (S3), not a
fixed poll.

---

## Units, defence and fleets

### U1 — Role derivation — what units are for

**Gaps:** G3, G4, G15, A2 · **Host:** unit objects with their properties (`capacity`, `fuel_capacity`,
`speed`, `structural_integrity`, `shield`, `attack`, drive type and level), their requirement graph, and
the unit queue (`UnitQueueService::add` — which **silently returns** when unaffordable, so the module
must pre-check).

**Rule.** A fleet is assembled from **roles**, and every role is computed from host properties, never
from a ship name:

```
cargo        = the unit with the largest capacity per unit of resource cost that the account can build
escort       = units whose attack-per-cost clears the observed defence (see T2)
recycler      = the unit the host's recycle mission requires
probe         = the unit the espionage mission requires
colony ship   = the unit the colonisation mission requires
```

**Finding.** Published doctrine gives roles and quantities but no ratios — Gameforge's raider table is
"Battleship 20–50+, Cruiser 10–30, Recycler 5–20, Large Cargo 10–30, Espionage Probe 10+", labelled
"recommended quantity" (**documented**) — while the corpus's *counter map* (light fighter → cruiser,
battleship → reaper, destroyer → deathstar) is a hardcoded table with 20% random mutation inside a
genetic search (ogame-fleet-optimizer — **verified**). **The counter map is exactly what gate 1
forbids**, so ranking comes from the host's own reported outcome or the host's rapid-fire data, and the
initial composition is a role split, not a table.

**Accept.** A mod-added ship with better capacity-per-cost becomes the cargo unit with no module edit.

### U2 — Cargo sizing

**Gaps:** G3, G6 · **Host:** `UnitCollection::getTotalCargoCapacity()`.

```
cargos = ceil(expected_payload / host_capacity(cargo unit)) × (1 + persona.cargo_surplus)
```

**Finding.** Every project sizes cargo from capacity rather than guessing, and halfguru's surplus of
10% is representative (**verified**). TBot reserves a minimum fleet of cargos before raiding and a
minimum free-slot count before launching (**verified**) — the "keep a fleet at home" habit.

### U3 — Defence — unprofitability, not ratios

**Gaps:** G3, A2 · **Host:** defence unit prices and properties, the unit queue, and the same
observation path as raids.

**Rule.** Defence exists to make an attack unprofitable, which is the doctrine verbatim — "the goal of
defense is not to survive a battle with the attacker, but rather to inflict maximum possible damage,
making the attack unprofitable" (**documented**) — and no source anywhere publishes a defence-to-value
ratio (**finding**, after checking the defence tutorial, the miner guide and every other recovered
page). So the module computes it: cost of defence ≈ the loss an attacker of the observed size would take
(host-priced), bought when an attack of that value is plausible from legal observations, and never
bought to match a number in a table. TBot builds anti-ballistic missiles only when a missile attack is
actually incoming (**verified**) — the same trigger.

### U4 — The fleet ledger

**Gaps:** U1, G4, A4 (register) · **Host:** own fleets with their missions, cargo and ETAs.

**Rule.** Own resources are always `on planet + in flight`, never just on planet; a ship under
construction is not available; a fleet in flight is not a fleet at home. This is
[E4](#e4-ferrying-resources-between-own-planets)'s netting applied to the whole account, and it is what
prevents the two classic errors: raiding with the fleet that is already flying, and spending resources
that a transport is about to deliver.

### U5 — Stage composition — the production stock

**Partially shipped** — cargo is sized to the raid payload (FLE-010) in `QueueableUnitPlanner`; the
stage workhorse (FLE-014) and recycler sizing (FLE-009) stay open.

**Gaps:** W6-4 · **Host:** unit objects with their properties (`capacity`, `fuel_capacity`, `speed`,
`structural_integrity`, `shield`, `attack`, rapid-fire), their requirement graph, the unit queue
(`UnitQueueService::add`), and the universe's stage (age, typical target defence).

`QueueableUnitPlanner` owns roles but no composition: cargo is fixed at one and there is no fodder,
counter or recycler sizing. The published fix is a **stage-sized stock** — production builds the hulls
the account owns, in the quantities its stage of the universe makes useful, and a launch picks the
subset it sends ([U6](#u6-the-launch-subset)).

**Rule.** Production sizes each [U1](#u1-role-derivation--what-units-are-for) role against the
account's stage, never against a remembered table:

- **fodder** — the light fighter is the cost-effective hull; the heavy fighter is early-only and ages
  out once gauss/plasma appear (FLE-006, **documented**);
- **workhorse** — the cruiser has the best damage-per-resource of the mid-game ships; the battleship
  (standard at 100–1,000) ages out as battlecruisers and reapers (rapid-fire ×7 vs battleship) become
  common (FLE-014, **documented**);
- **recyclers** — sized to clear the account's own solo debris, tracking heavies and expected debris,
  never a constant (FLE-009, **documented**);
- **cargo** — sized to payload, not fixed at one: small cargo scales with raid follow-up, large cargo
  with held resources (FLE-010, **documented**); the first cargo while no fleet exists stays the one
  exception.

**Constants.** Gameforge's raider table — "Battleship 20–50+, Cruiser 10–30, Recycler 5–20, Large
Cargo 10–30, Espionage Probe 10+" — is a **shape** for a stage band, not a table to encode
(FLE-004, **documented**). Stage bands are persona policy; the hulls they name are host objects.

**Gate.** The stock is a quantity per host-derived role; the stage is read from host state, and a
mod-added hull with better damage-per-resource becomes the workhorse with no module edit.

**Accept.** A mod-added rapid-fire pair changes the counter selection, and a mod-added cargo hull
with more capacity changes the cargo sizing, both without a module edit.

### U6 — The launch subset — counters, not the whole stock

**Gaps:** W6-4 · **Host:** the target's hull mix and defence from its espionage report, the rapid-fire
graph, and the battle engine for simulation.

Production and launch are two decisions (FLE-012): the stock is what the account owns, the launch is
the minimum hulls that win against *this* target. Sending the whole stock every time is the error the
split exists to prevent.

**Rule.** Launch is counter-selected per target from the host's own rapid-fire graph — pick hulls with
rapid fire against the target's mix and deny it against your own (FLE-007, FLE-013, **documented**):
cruisers against light-fighter swarms, destroyers against battlecruisers, mixed hulls to dilute
Deathstar rapid fire. Fodder rides along only when the target can threaten the heavies; an undefended
target collapses to kill ships plus cargo. Every attack is simulated against the target's actual
fleet and defence before dispatch, because one simulation is a probability average and close fights
vary (FLE-015, **documented**). The debris path is a separate trip, never folded into the raid
(FLE-011, **host**).

**Constants.** Rapid fire is `P_repeat = (r − 1) / r` from the host's own unit data; the counter pairs
(light fighter → cruiser, battleship → reaper, destroyer → deathstar) are host data, never module
memory — a hardcoded counter map is exactly the gate-1 violation the corpus's genetic search commits
(FLE-007, **verified**).

**Gate.** The split is one extra decision between `QueueableUnitPlanner` (production) and the raid
dispatch (launch); the counter graph and the simulator are host capabilities, not module reimplementations.

**Accept.** A target with a light-fighter swarm draws cruisers as the launch subset; the same stock,
presented an undefended farm, sends kill ships plus cargo instead of the whole fleet.

---

## Saving and reaction

### V1 — The save state machine

**Gaps:** G4, G9 · **Host:** the mission catalogue (`GameMissionFactory::getAllMissions()` — there is no
mission enum, so a mod-added mission is reachable), fleet slots, fuel quote, distance and duration
quotes, deployment's non-recallable self-relocation rule, and `cancelMission` (which has **no ownership
check** — the module must do it).

```
on entering an absence of length T:
    target_duration = max(T + persona.buffer, inbound_eta × 1.3 if a fleet is inbound)
    enumerate legal own destinations × speed steps
    discard: unaffordable fuel, no free slot, no cargo room for the resources we want to lift
    rank by exposure (distance, planet-versus-moon, phalanx risk), then fuel
    take the first route whose flight time covers target_duration
    optionally schedule a recall at half the flight (see V3)
```

**Constants.** TBot's `minFlightTime = inbound arrival × 1.30 + jitter` for a defensive save and
`wake − departure` for a sleep save, recall registered at **half** the required duration, and a
deliberate deuterium leftover of 200,000 so the save does not look like an emptied account (**all
verified**). The guides add the landing rule: "ALWAYS time your fleet to land AFTER you expect to be
online", the "+30–60 minutes" buffer (Gameforge) and "10–20 minutes after you log in" (a single
non-Gameforge source — **documented, single-source**), and the hard rule "never save to the same landing
time every day" (**documented**). Cruise enumerates speed 1–10 as a first-class axis and subtracts fuel
before loading cargo (**verified**), which is the difference between a save that flies and one that is
refused at the pad.

**Gate.** All destinations, costs and durations are host quotes; the ranking is a tuple, not a model;
and it is exactly what a player does before logging off.

**Accept.** No save is ever planned to land inside the sleep window; two consecutive saves to the same
destination do not share a landing time.

### V2 — The reaction window

**Gaps:** G8, G9 · **Host:** own active fleet missions (origin, ETA, composition — assembled from
`getActiveFleetMissionsForCurrentPlayer()` plus the unit collections, which is what the fleet controller
does; `IncomingFleetIntelService` is a **redactor, not an intel API** and reports nothing), and the
planet's last-update stamp.

```
if the earliest inbound fleet lands in more than the reaction window:
    schedule a wake at (arrival − randint(min, max))
if it already lands inside the window: do not attempt a doomed save; check after impact
```

**Constants.** `min = 120 s`, `max = 180 s` before impact (Cruiser — **verified**), with the host's own
bot detector naming **10 seconds** as the floor below which a reaction is flagged as scripted
(**host**). So the module's latency band is `> 10 s` and inside the window, right-skewed, never constant.

**Finding.** No project models a reaction it *missed*; every one is best-effort with a generous window.
That is the blindness [V3](#v3-the-save-that-fails) corrects.

### V3 — The save that fails

**Gaps:** G9 · **Host:** none beyond V1's — this is a persona parameter.

**Finding, stated as a finding.** Not one of the eleven projects implements a deliberate failure, a
save-success probability or a mistake rate; the only concession anywhere is halfguru skipping a speed it
cannot fuel, and a reaction abandoned when the window is too small (**all verified**). No source
quantifies how often real players fail either: the widely repeated "80% of fleets are lost while
offline" has **no source at all** and must be deleted wherever it still appears. The named failure modes
are documented and qualitative: the "just this once" overnight gamble, forgetting to relaunch after
landing, saving to a planet instead of a moon, a predictable return time, landing with a full cargo
(**documented**).

**Rule.** One persona parameter set: a save-failure rate of `1 per 20–50 attempts` and one lost fleet
per `1–3 months` (**placeholder**), realised as a small set of *named* ordinary mistakes — the overnight
gamble, the forgotten relaunch, the same landing time twice. The realisation is a skip, not a coin flip
on the whole path: the account must still take a legal action, and the loss must be recoverable through
the ordinary recovery behaviour. This is the only mechanism in this file that deliberately reduces the
account's performance, and it exists because a 100% save rate is the observable that would otherwise
give the cohort away.

**Accept.** Over a four-week pilot, at least one deliberate failure occurs per account, each one is
named in the record, and every one is followed by the recovery path — with the loss visible in the
public military-lost column, which is what a human account's history looks like.

### V4 — Recall, and the return window

**Rule.** A save may be recalled only if the return would land **inside the safe window** — otherwise
the fleet comes home into the danger it was sent away from. Cruiser's implementation of this rule is
documented as comparing a *remaining* return time but actually compares elapsed time (**verified
discrepancy**), so we take the intent and not the code: the predicate is over `now + return_duration`
against the window, derived from the persona's obligation bounds rather than from a second constant.

A recalled deployment is also the **phalanx-invisible save** (FS-010): a deployment disappears from
the phalanx, so deploy-recall is named the safest pre-moon save (**documented**). The host seam is
`cancelMission` → `startReturn`, with **no ownership check** (the module must add it), and it applies
to a deployment between two own planets only — a same-planet relocation
(`planet_id_from === planet_id_to`) is **not** recallable (**host**). The offensive use of the same
seam is [F2](#f2-deploy-recall-interception).

### V5 — The save is a plan, and the plan is visible

The save's duration comes from the same absence model the routine uses ([H3](#h3-absence)), so a
planned week away produces a week-long save and a 40-minute gap produces a 45-minute one. The two
mechanisms read one parameter, not two.

### V6 — The proactive save

**Shipped** — the save fires on two triggers: the reactive one (an inbound hostile) and the
proactive one (logging off for a real absence with a fleet worth losing). The session plan is
computed before the decision, so the decision sees the absence this session is about to enter as
`upcomingAbsenceMinutes`; `QueueableFleetSavePlanner::proactivePlan()` offers the save only when
that gap clears the routine's own inter-session wait and the fleet left behind clears the persona's
exposure band.

**Gaps:** W6-3 · **Host:** ship objects and their raw prices (`ObjectService::getObjectRawPrice`),
the session plan (H3), cargo capacity, planet stock, free fleet slots.

The save today fires on `currentPlayerUnderAttack()` — reactive — and now also before a real
absence: "if you go offline > 30 minutes with a valuable fleet, fleetsave it" (FS-001,
**documented**). The save is complete because cargo is loaded: in-flight resources cannot be raided
and a stripped planet is unprofitable to hit (FS-006, **documented**).

**Rule.** On entering an absence longer than the routine's inter-session gap, save when the fleet
left exposed clears the persona's exposure band, and lift the planet's stock into cargo up to
capacity (FS-006).

**Constants.** The absence threshold is 120 minutes — above the densest persona's routine gap (a
fleeter's ~70 minutes between sessions) and below the dark period, so a save fires at the last
session before bed, not every session (FS-001, **documented**). The exposure band is a persona
parameter over fleet value: 5,000 resources for a fleeter, 25,000 for a trader, 50,000 for a miner,
turtle or casual player. Fleet value is the raw-price sum of the ships parked on the origin planet,
defence excluded — a save moves ships, never a planet's built defences (FS-001).

**Gate.** The trigger is a tuple over host quotes (fleet value, stock, capacity, duration) and it
is exactly what a player checks before logging off. The band is persona taste over host data, never
a gate-1 object list.

**Accept.** A fleeter's last session before bed saves the fleet; its daytime sessions still build.
A miner with no ships, or a fleet below its bar, never saves proactively.

### V7 — Variation, route scoring and dispatch masking

**Shipped** — the save destination is scored to the farthest own planet (FS-005); dispatch masking
is satisfied by the routine cadence, never a ping. The `destination × speed` enumeration collapses
for a deployment save: the host's slowest speed (10%) is also the minimum-fuel speed, and a parked
deployment has no arrival schedule to fit, so the two route axes reduce to the shipped destination
ranking at the fixed slowest speed. Two residuals are recorded, not built: (1) discard unaffordable
fuel — the host already refuses an unfuelable save at dispatch and the receipt records the refusal,
so a planner-side fuel filter is observability polish, not correctness; (2) never the same landing
time every day — the save's departure rides the routine's own session spread (H2), which already
varies the landing time, never a fixed tick.

**Gaps:** W6-3 · **Host:** owned destinations, speeds, fuel quotes, distance and duration quotes.

The save is scored over `destination × speed` routes on exposure, fuel and schedule fit — not the
first other own planet at speed 1.0 (FS-005, **verified** route enumeration; **documented** "never
save to the same landing time every day"). After dispatch the account stays on briefly so the
departure timestamp is not readable off the activity star (FS-008, **documented**): the masking work
is the same real work the routine would do next, never a keep-alive ping ([H4](#h4-the-activity-marker-is-a-side-effect-never-a-ping)).

### V8 — Shadow waves

**Shipped** — a large fleet is split across two own bodies so a phalanx-timed crash catches only
part. The split is decided in `QueueableFleetSavePlanner` and carried as a second destination; the
dispatch action sends the combat hulls to the safer body and the civil hulls, which lift the stock,
to the other.

**Gaps:** W6-3 · **Host:** fleet slot count (`getFleetSlotsMax` − `getFleetSlotsInUse`), the host's
military/civil ship classification, fleet size, own destinations ranked by safety.

**Rule.** A large fleet is split across saves so a phalanx-timed crash catches only part (FS-009,
**documented**); the ceiling is the slot count, and a small fleet is never split. The split needs
four things at once: a second own body, a free second fleet slot, both hull roles present, and a
fleet at least twice what the persona bothers to save — each wave is still worth the trip. This is
the [V1](#v1-the-save-state-machine) enumeration narrowed to its top two routes; the full
`destination × speed` enumeration is resolved under [V7](#v7-variation-route-scoring-and-dispatch-masking)
— the slowest speed is the minimum-fuel speed and a parked deployment has no schedule to fit, so the
speed axis never changes the chosen route.

**Gate.** The split is a tuple over host quotes (slots, hull classification, fleet value) and it is
exactly what a player does with a fleet too large to park in one place.

**Accept.** A fleeter with combat and cargo hulls parks the combat wave on its moon and the cargo
wave on a planet; a single-role or small fleet stays on one body.

---

## Intelligence

### N1 — Probe sizing

**Gaps:** G5 · **Host:** the espionage mission, the observer's espionage technology, and the visibility
thresholds the host enforces (`EspionageMission::canRevealData`: ships 2/1, defence 3/2, buildings 5/3,
research 7/4, plus the quadratic gap when the defender is ahead).

**Rule, in order of preference.**

1. **Solve the host's own counter** for the probes that reveal ships and defence:
   PHPOgameBot's closed form computes the minimum probes for a desired result set instead of sending a
   fixed number (**verified**); the thresholds must come from the host's table, not from a copy.
2. **Escalate** when the report is incomplete: TBot re-probes at ×3 then ×9 the original count and marks
   the target unsuitable after that (**verified**). The escalation is cheapest-first and bounded.
3. **Give up** on a target that still does not reveal, rather than paying forever.

**Constants.** Published play is 5–10 probes (Sidian) and "10+" (Gameforge) — **documented and mildly
contested** — so the count is a persona habit consistent with the host's requirement, not a constant in
code.

### N2 — Target lifecycle

**Gaps:** G5, G6, S4 · **Host:** galaxy views, own reports (with their timestamps), the inactive and
long-inactive flags, and the host's attack log for the bashing limit.

**Finding.** TBot's target state machine is the most complete artefact in the corpus and it is directly
reusable as a *shape*: states `Idle → ProbesPending → ProbesSent → AttackPending → AttackSent`, with
`ProbesRequired` and `FailedProbesRequired` as escalation states and `NotSuitable` as the terminal one;
dedupe by coordinates keeping the first; a report kept for `KeepReportFor 180` minutes; reports deleted
after every pass; a loot floor (`MinimumResources 1,000,000`); `TargetsProbedBeforeAttack 30`; a maximum
of 18 concurrent attack missions leaving one slot free; and a five-iteration retry loop for a slot
(**all verified**, values from its shipped settings file). The state names are policy; the filter's
"inactive and defenceless" becomes a **legal observation** in our module, never a column list (its
seven defence plus fourteen ship columns are a gate-1 violation and are the reason its filter silently
breaks when a ship is added — **verified**).

**Constants to keep as persona bands, not constants:** report age before re-probing (180 min),
blacklist duration after a bad outcome (7 days), maximum concurrent missions per cycle (3–8 in the
corpus), minimum rank to attack (`MinimumPlayerRank 500` in TBot, 3,000 in ogame-ninja), minimum loot
(50,000 in trilogi77, 1,000,000 in TBot — a 20× spread, which is exactly why it is persona policy).

### N3 — Freshness, and refusing to guess

Every fact carries `{source, observed_at, expires_at, confidence}`. Resources decay fast, coordinates and
past losses slowly. A report that has aged past its persona band is **stale**, and a stale report may
support a probe or a rejection but never an attack ([T2](#t2-the-estimator) lowers the claim instead of
assuming). Unknown defence is never zero defence.

### N4 — Spy target prioritisation

**Shipped** — `QueueableSpyPlanner::target()` ranks the bounded unknown set by known yield minus
host distance and skips a just-touched target.

**Gaps:** W6-2 · **Host:** galaxy positions, distance and fuel quotes, own reports and their
timestamps, the [activity reader](#sp7-the-activity-and-intel-reader).

`QueueableSpyPlanner::target()` walks planets in `id` order and returns the first legal unknown — a
mechanical signature. Score the bounded unknown set instead: distance (cheap flight), likely yield
(prior reports and galaxy position), novelty (unseen recently) and activity (skip a just-touched
active target — probing an active target repeatedly is itself a tell; INT-003/009, **documented**).
The score slots into the existing candidate/trace mechanism; it adds no second decision path.

### N5 — Own signature control

**Shipped by construction** — `QueueAiSpyAction` dispatches and returns, doing no further
planet-context work on the launch body.

**Gaps:** W6-2 · **Host:** the activity reader over our own bodies.

Refreshing the launch planet after sending creates an activity chain that advertises "something is
in flight" (INT-007, **documented**). Dispatch, then do no further planet-context work on the launch
body until the next scheduled need. This is the negative-space twin of
[H4](#h4-the-activity-marker-is-a-side-effect-never-a-ping): the marker is never manufactured, and
it is never *not* produced when real work requires it.

---

## Raiding and estimation

### T1 — The profit test as an audit trail

**Gaps:** G6, G9, S4 · **Host:** the observation's resource and defence fields, the fuel quote for the
round trip, the fleet's cargo capacity, the host's debris percentage, recycler capacity, the 24-hour
attack count for that planet, and free fleet slots.

The publisher's formula is the audit trail, quoted rather than paraphrased: *"Profit = Loot − Deuterium
consumption − expected ship losses"*, *"the stolen resources should be at least triple the deuterium
consumption"*, *"expected ship losses may account for maximum 20–30% of the loot"*, *"a raid with a
negative result is not a raid — it's a donation to the opponent"* (**documented**, Gameforge raider
guide). Every term is a host quote or a sum of host quotes. Loot is the host's own plunder rule, 50% of
stored resources (75% for one class) — **documented**; note the multi-wave "loot split" that our earlier
draft described is **not** on any page and is retracted in
[Corrections](#corrections-to-earlier-docs).

### T2 — The estimator

**Gaps:** G6 · **Host:** the battle engine (`BattleEngine::simulateBattle()`), the fuel quote, unit
properties. The engine is authoritative and **not seedable**, and there is **no side-effect-free
simulation entry point** — this is the single most load-bearing host fact in the whole design
(**host**; see [the capability map](../research/host-capability-map.md)).

**Findings that constrain the design.**

* **Mean profit is dangerous, and there is a documented casualty.** TrashSim's own changelog adds
  "subtract fuel costs from profit" and a bounce fix, and a player in the same thread reports "using this
  sim saying profit 30kk in fact I lost 30kk" (**verified quotes**). A single mean that omitted one rule
  produced a sign-flipped answer.
* **Nobody models defender uncertainty.** Every surveyed simulator takes the defender as a point
  estimate (**verified by inspection of each tool's inputs**), so treating the report as truth is the
  industry default and is exactly what our intel ages are meant to correct.
* **Common random numbers are the one methodological idea worth taking.** ogame-fleet-optimizer gives
  every individual in a generation the **same seed** so that fitness differences come from composition
  and not from luck, with a wall-clock deadline checked mid-evaluation and a screening-then-confirmation
  ladder (10 → 50 → 100 sims, 200–1,000 to validate) (**verified**).
* **Its hard constraint is wrong for us.** "-inf if win probability < 0.95" cannot be certified from 10
  or 50 runs, and fires on noise (**verified** — the same source's own ladder is the contradiction).
* **Expected-value engines exist** (opbe computes one deterministic expected-value pass in O(1); its own
  README tells you to check accuracy against "3k" simulations) — **verified**, and not adopted: it is a
  modelling choice, not a measurement of variance.
* **Nobody prices fleet slots or warship payback.** Damage-per-cost as an ordering key is the only
  cost-effectiveness metric with a source (**verified**).

**Rule — the smallest bounded estimator.**

```
candidates:  the fleet we own + up to 4 variants that shift one role up or down          # no subset search
states:      the observation, plus 2–5 plausible defender states built from it
             (defence within the observed band; a fleet the report may have hidden)      # decayed by age
seeds:       seed = hash(decision_key) + candidate_index * n + run_index                # CRN
sampling:    n = 50 screening, shared seed stream across candidates;
             n = 200 on the winner before committing
deadline:    wall clock, checked between candidate batches; unevaluated candidates are dropped
report:      losing_runs/n, P20 net profit, mean net profit, mean loss value, debris value,
             loot value, fuel deuterium, observation age, 24-hour attack count, estimator version
decision:    reject any candidate with any losing run at n = 50 unless the persona accepts risk;
             order the survivors by P20 net profit
```

**Gate.** Bounded (gate 2): one loop, five candidates, two sample sizes, one deadline — not the genetic
search the source uses. Host-quoted (gate 1): every money term is a host quote, and no ship name or
counter table appears. Namable (gate 3): a player reads a simulator's win rate and worst case and then
decides; "not losing is the first question" is how the sources put it themselves.

**Accept.** Same seed and same snapshot produce a byte-stable decision; a candidate that wins 50/50 runs
but loses 10% of its value in the worst decile is refused by a cautious persona and taken by an
aggressive one.

### T3 — The bashing limit

**Gaps:** G6 · **Host:** the host's own attack records for the target body.

The rule is quoted, not paraphrased: no more than **six attacks per planet or moon per 24 hours**,
moon-destruction missions count, probe attacks and interplanetary missiles do not, and a fleet that was
completely destroyed does not count either (**documented**, rules §4; the wiki adds per-universe variants
for U30/35/40/42). **Rule:** the module reads the count from the host's own records rather than keeping a
tally, because "destroyed fleets do not count" means a module-side counter would drift out of step with
the rule that is actually enforced.

### T4 — Debris and the second trip

Debris is usually 30% of the destroyed ships' value, metal and crystal only; a recycler lifts 20,000
units; a debris field of 100,000 gives a 1%-per-100,000 moon chance to a maximum of 20%; and probes sent
on an attack mission create a small field deliberately (**documented**). It is **never** guaranteed
income — someone else may collect it first (**documented**) — so it enters the estimator as a value
discounted by the recycler's travel time and the account's own prior success at collecting, and the
recycle mission is only queued when the field survives the trip estimate.

### T5 — Report sharing

Signal 5 counts shared reports as social evidence, and the module already has the delivery ledger.
Sharing a report is the same authored, permission-checked path as any other social action
([SOC1](#soc1--speaking-first)); it never carries an observation the sender could not legally make.

### T6 — The raid target score

**Partially shipped** — activity risk, intel freshness, travel cost, the score-ratio pre-filter, the
storage-fill raid schedule and proximity clustering are wired in; relationship (RAID-007) and contest
(RAID-013) are recorded here and deferred.

**Gaps:** W6-1 · **Host:** target `Planet.time_last_update` and galaxy activity via the
[activity reader](#sp7-the-activity-and-intel-reader), per-type report freshness, distance and fuel
quotes, fleet-slot occupancy, target and own public scores, the relationship state.

The estimator answers "is this raid profitable"; the *choice* among profitable reports comes from
the published signals: `PlayerObservationService::targetReports()` publishes a per-type intel
`confidence` and a normalised host-distance `travel_cost`, so a fresh, nearby target outranks a
stale, distant one.

**Rule.** Order raid candidates by the estimator's P20 net profit plus the missing signals, each a
host quote or a read of existing state:

- **activity risk** — `activity_probability_at_eta` from the target's last-update stamp; a target
touched close to flight raises interception risk (RAID-004);
- **intel freshness** — per-type confidence decay replaces the flat `1.0` (RAID-005);
- **travel and slot cost** — the round-trip fuel and an occupied fleet slot, replacing the `0.0`
placeholder (RAID-006);
- **proximity** — the storage-fill schedule is shipped: `RaidPlanner::storageReady()` gates the raid
on the fleet planet's warehouse being near full (0.8 of capacity), so the account raids on the
8–12h fill cycle rather than ad hoc (RAID-009); clustering by galaxy distance is also shipped:
`travel_cost` is the host-quoted distance normalised over the universe, and the host's own distance
quote prices a cross-galaxy hop at `diffGalaxy × 20000` against `deltaSystem × 95 + 2700` inside a
galaxy — roughly the documented 5× deuterium — so the scorer already clusters raids near the fleet.
Pinned by `owned state prices a distant target higher than a near one`;
- **contest** — popular farms are cleaned out fast; the edge is proximity or a schedule others miss
(RAID-013, **deferred** — the module observes no other player's raid schedule, so a contest model
would be an unmeasured guess; proximity is already the shipped edge);
- **relationship and archetype** — a past ally or debtor is raided differently (RAID-007,
**deferred** — `AiRelationship` rows are only written by the social observation path, which ships
with Package 6; until that state is populated a raid policy over it would be dead code).

A pre-filter drops a target whose public score is under ~⅕ of ours — it cannot defend economically
against the fleet class (RAID-008, **shipped**): `targetReports()` publishes `score_viable` from the
host's public highscore (`general`), and the factory rejects a non-viable target as
`score_below_viability` before the estimator runs. An unknown own score filters nothing, so a young
universe does not skip everything. The score exposes its components in the existing trace, so
"why this target" stays answerable deterministically.

### T7 — Tiered profit gate and cargo sizing

**Shipped** — the tiered loot-to-fuel gate and cargo-capped expected loot in `RaidPlanner::plan()`.

**Gaps:** W6-1, W6-4 · **Host:** loot, fuel and debris quotes, target defence, the report's visible
resources, the class loot multiplier, cargo capacity.

The flat `p20NetProfit <= 0.0` gate becomes a **risk-tiered** gate: 3:1 loot-to-fuel on routine
farms, 2:1 where debris subsidises a defended run, and below 1.5:1 refused as marginal (RAID-011,
**documented**). Cargo is sized to the report, not fixed at one: count = expected loot (50% of
visible resources, 75% for the looter class) ÷ cargo capacity, plus a 20% buffer (RAID-012,
**documented**). An under-cargoed raid wastes fuel already sunk; an over-cargoed one spends ships it
did not need.

### T8 — Launch-time re-check

**Shipped** — `QueueAiRaidAction::handle()` refuses a target whose activity star is lit at dispatch.

**Gaps:** W6-1 · **Host:** the target's last-update stamp at dispatch.

Between planning and dispatch the target may have logged in. If the activity reader shows the target
touched within the window at dispatch, abort or delay the raid rather than fly into a recall or a
ninja (RAID-010, **documented**). This is a check in `QueueAiRaidAction::handle()`, not a new
planner.

---

## Fleetcrash, phalanx and moon

The largest strategy gap: the host supports phalanx, moon, jump gate, debris, recycle and recall,
and the module reaches none of them ([architecture mapping](../research/architecture-mapping.md)).
Every block below is host-quoted and **unsupported-until-verified** — the executor exists only after
a reviewed slice. The [classical pattern catalogue](../research/classical-ai-patterns.md) confirms
these are mechanisms an experienced fleeter names, not inventions.

### F1 — Phalanx coverage

**Status:** deferred — the scan is the host's `canScanTarget`/`getScanCost`; a module range table would violate gate 1 and a forward over it gate 2. Its only consumer is the crash-timing executor.

**Gaps:** W6-5 · **Host:** `PhalanxService::calculatePhalanxRange` (level²−1, Discoverer +20%) and
`getScanCost` (5,000 deuterium), the planet/moon distinction.

The phalanx reveals return fleets within its range and nothing else: scan only what the coverage
covers (CRASH-002). It is a two-sided tool — the same phalanx that times a crash on an enemy return
is the reason a planet-launched save is unsafe (CRASH-007). A moon is outside every phalanx
(CRASH-006), which is why the moon-to-moon deploy is the safest save ([F3](#f3-the-moon-as-geography)).

**Gate.** Range and cost are host quotes; the block is "scan within coverage", never a range table.

### F2 — Deploy-recall interception

**Status:** shipped (defensive side) — `QueueAiRecallAction` recalls the account's own in-flight save over `cancelMission` with the ownership check the host lacks; the offensive half-flight crash timing rides the deferred crash executor.

**Gaps:** W6-5 · **Host:** `cancelMission` → `startReturn` (no ownership check — the module adds
it), the flight-time quote.

**Rule.** A recalled deployment is the tool on both sides: recall a deployment at about half its
flight to time a return (CRASH-003, **verified** at 98–101% of half flight), and use the same seam
to make one's own save phalanx-invisible (FS-010;
[V4](#v4-recall-and-the-return-window)). The recall applies to a deployment between two own planets
only — a same-planet relocation is not recallable (**host**).

### F3 — The moon as geography

**Status:** shipped — the save planner parks on a moon when one exists (phalanx-invisible); building one stays an economy decision, left open.

**Gaps:** W6-5 · **Host:** `PlanetType::Moon`, `JumpGateService::calculateCooldown`
(60 / fleetSpeedWar minutes, −10% per level, minimum 1 minute).

A moon is not decoration: it is a phalanx-invisible launch pad (CRASH-006) and a jump-gate endpoint
whose cooldown is a host quote (CRASH-004). Once a moon exists the save planner treats it as a
lower-exposure route; *when* to build one (Lunar Base) is an economy decision scored like any other
investment, left open until a cluster justifies a block.

### F4 — The recycle trip

**Status:** deferred — the host seam is verified (`DebrisFieldService::calculateRequiredRecyclers`, `RecycleMission` type 8) but the trip needs debris-field awareness plus the attack→debris→recycle chain of the crash executor.

**Gaps:** W6-5 · **Host:** `DebrisFieldService::calculateRequiredRecyclers`
(ceil(debris ÷ recycler capacity)), `RecycleMission` (type 8, has a return mission; requires a
recycler in positions 1–15 or a pathfinder at 16).

A crash's debris is a **second mission with its own capacity and timing**, never added to the raid's
profit (CRASH-001; [T4](#t4-debris-and-the-second-trip)). The recycle mission is queued only when
the field survives the trip estimate, and the recycler count comes from the host's own
`calculateRequiredRecyclers` (20,000 per recycler — **documented**). This is the first of the
deterministic sequences (ZH-7): attack → debris → recycle, expressed as a planned chain, never an
LLM-coordinated loop.

### F5 — Blind lanx via the debris field

**Status:** deferred — P2 awareness-first per plan; the observer-side executor waits for the crash cluster.

**Gaps:** W6-5 · **Host:** debris-field visibility (> 300 units — **documented**), recycler arrival
timing.

A harvest save leaks its arrival the instant its debris field vanishes; an observer back-calculates
the return and crashes it without ever seeing the fleet (CRASH-008, **documented**). This is
observer-side awareness in both directions: the module consumes it to infer a victim's return, and
knows its *own* harvest save is visible the same way. P2 — awareness first, executor later.

### F6 — Moon destruction

**Status:** deferred — last in priority, most niche and expensive; the host's redirect (`redirectFleetsFromMoon`) is now code-verified but the plan keeps it behind owning a deathstar and the strategic judgement.

**Gaps:** W6-5 · **Host:** `MoonDestructionMission` (type 9), deathstar availability.

Destroying a moon redirects its returning fleets to the planet (phalanx-visible) and auto-recalls
foreign fleets en route; no debris field results (CRASH-005, **documented**; the fleet-redirect
consequence is **not re-verified** against the host). This is an offensive planner over the type-9
mission, gated on owning a deathstar and on the strategic judgement that the moon's loss is worth
more than the fleet it guards. Last in priority — the most niche and the most expensive.

---

## Ninja and baiting

The ninja is the defender's counter-crash: a raid that flies into a staged trap dies as the attacker.
It is one mechanism with two sides — the account executes it when *it* is the defender, and checks
against it on every raid it launches. The host supports both (fleet movement, moon staging, the
combat second); the module reaches neither today. `NN1` rides the existing raid path; `NN2` is an
advanced tactic gated behind a reviewed cluster, never silent.

### NN1 — Anti-ninja checks on the raid path

**Status:** shipped (moon staging) — the dispatch drops a raid whose target moon is active while its
planet is quiet, the defender moving a trap fleet on the moon. The phalanx scan and last-second probe
stay habits: the phalanx needs a sensor phalanx no account yet builds (F1 deferred), and the probe is
the existing spy cadence.

**Gaps:** pass-6 (new) · **Host:** phalanx coverage (`PhalanxService`), probe timing, the target's
fleet-movement signals, the activity reader.

An attacker defends against a ninja by checking for staging, not by hoping (NIN-005, **documented**):
phalanx the target's nearby planets for staged fleets, probe when no moon is available, abort when a
defensive fleet movement appears, and send a last-second probe to confirm the defender's fleet is
still on the planet before impact.

**Rule.** Extend [T8](#t8-launch-time-re-check) from an activity re-check to a staging re-check: a
raid candidate is dropped when the defender shows fleet movement toward the bait, or when the
last-second probe no longer confirms the defence the profit math assumed. This is a check in
`QueueAiRaidAction::handle()`, not a new planner.

**Constants.** The last-second probe is a habit, not a new cost centre; phalanx cost and range are
host quotes ([F1](#f1-phalanx-coverage)).

**Gate.** The check reuses the phalanx seam and the probe path the module already owns; no new
simulation, no new planner.

**Accept.** A target whose defender launches a staged fleet after planning is dropped before impact,
not raided into a trap.

### NN2 — The ninja trap (defender counter-crash)

**Status:** deferred — gated behind a reviewed cluster (advanced tactic, never silent); the
same-second landing is timing-critical, and a wrong landing loses the fleet. The save stays the
default.

**Gaps:** pass-6 (new) · **Host:** own moons, the deployment/return mission and its timing, the
combat second, the espionage report the attacker reads.

A ninja keeps the real defensive fleet away from the bait planet — hidden on a nearby moon or away on
a mission timed to return before impact — and lands it on the combat second, so it fights as defender
and kills the attacker (NIN-001, NIN-004, **documented**). The bait must read as a soft farm: a
resource pile large enough to make the raider's profit math positive while stationary defence stays
light (NIN-003, **documented**). A fortress deters; the trap lures.

**Rule.** When the account holds a fleet and a moon (or a timed-return seam) and an inbound attack is
observed ([V1](#v1-the-save-state-machine)), it may choose the ninja
instead of the save: stage the defensive fleet off-planet, leave a bait pile, and land it on the
attacker's impact second. The landing is the same-second server-tick buffer, never seconds early —
early exposes the fleet to a recall, late misses the battle (NIN-002, **documented**).

**Constants.** The landing buffer is ~1 s (server-tick), reconciled from WIK-005 and TP-011
(`CLAIM-NIN-ARRIVAL` — **contested, reconciled**); the bait pile is sized by the attacker's own
profit test, so it is host-derived rather than a stored number.

**Gate.** The ninja is the experienced-player name for "defend with a timed counter-landing"; it is
an executor behind a reviewed cluster, and the save ([V1](#v1-the-save-state-machine)) stays the
default, so a failed ninja is a failed save, not a silent kill.

**Accept.** An observed inbound raid is met by a staged fleet landing on the combat second, and the
account falls back to the save when it holds no moon and no timed-return seam.

---

## Colonies

### CL1 — Slot choice

**Gaps:** G7 · **Host:** the colonisation mission, `canColonizePosition()` (with its astrophysics
requirements per position), `getMaxPlanetAmount()`, and the per-position production bonuses the host
publishes (`getProductionForPositionBonuses()`).

**Finding.** The corpus's slot tables are hardcoded lists (`preferPositions [4..12]`, `maxColonies = 1 +
Astrophysics` in halfguru; `[4,5,6,7,8]` scored by list index in the same author's colonizer — **verified**),
and the guides are *contested* on what each slot is good for (crystal bonus "slots 1–5" versus "only 1–3";
position 8 "the classic deuterium slot" versus "slot 12–15 for deut") — **documented and contested**. So
the module ranks a **bounded** set of legal slots by the host's own published position bonus, weighted by
the persona's preference for the resource it is short of, discounted by travel cost and by the risk of
the region. There is no slot list in module code.

**Accept.** A host that changes its position bonuses changes the preference with no module edit.

### CL2 — The colony is another mine

**Gaps:** G7, G16 · **Host:** the new planet's own production, cost and queue state.

A colonisation is only worth it when the cheapest thing the colony could build out-pays the cheapest
thing the empire could build at home — which is how the miner guide describes it ("treat them like they
are mines … build whatever has the shortest return on investment") and how the memoir roadmap is
*derived* rather than tabulated. The colony then runs [E1](#e1-payback-ordering--the-next-mine) with its
own numbers.

---

## Expeditions

The host carries an expedition mission (type 15) with its own slot budget
(`getExpeditionSlotsInUse` / `getExpeditionSlotsMax`). The slot-16 coordinate requirement, the
astrophysics gate, the 1..astrophysics holding-hours bound and the configurable outcome weights were
verified against the host on 15 September 2026 (DISC-004 closed), so the executor reads the host
mission directly and never invents an outcome table.

### EX1 — Slot-16 outcomes, and never a save

**Status:** shipped — `QueueAiExpeditionAction` dispatches one disposable civil cargo ship to slot 16; the never-fleetsave refusal is the single-hull fleet.

**Gaps:** pass-6 (new) · **Host:** the expedition mission (type 15), the expedition slot count, the
holding-hours bounds, and — once verified — the slot-16 position requirement.

**Rule.** Two rules, both nameable as ordinary play. First, an expedition is **never a fleetsave**:
there is a small chance the whole fleet vanishes, so the expedition fleet is a small disposable set,
never the fleet the account depends on (EXP-001, **documented**). Second, expeditions target **slot
16** only, and the outcome is bounded and host-returned: found ships (never Death Stars), antimatter,
resources capped at cargo capacity, traders, an empty return, a pirate/alien attack, a rare total
fleet loss, and a navigation error that shifts the return time (EXP-002, **documented**). The module
never invents an outcome table — it reads whatever the host mission returns.

**Constants.** Resource rewards are capped at the expedition fleet's cargo capacity (**documented**);
the fleet size is a persona band, not a constant.

**Gate.** It is the host's mission with the host's slot budget; the module adds the never-fleetsave
refusal and a small disposable fleet, and nothing else.

**Accept.** The account dispatches an expedition to slot 16 with a small fleet while its save
([V1](#v1-the-save-state-machine)) still covers the real fleet, and a long absence never turns the
expedition into the save.

---

## Routine, cadence and absence

### H1 — The active-hours constraint

**Shipped 14 September 2026** in `SessionPlanner`, with [H2](#h2-session-shape).

**Gaps:** G10 · **Host:** the host's own detector (`ServerAdministrationController`, defaults
`bot_detection_lookback_days = 7`) and the 15-minute per-planet activity marker.

**This is a hard constraint, not a preference.** The host's signal 1 flags an account with **18 or more
distinct hours-of-day containing a mission departure in a 7-day window**, plus **18+ missions per fleet
slot per day** and a **50-mission floor**; signal 2 flags an expedition re-dispatched within **10 s** of
its return, 5 or more times; signal 3 flags a fleet departing within **10 s** of an attack departing
(**host**, verified in source with the defaults at their lines). So:

```
every rolling 7-day window must contain at most 17 distinct active hours
every day must contain a dark period of at least 6 consecutive hours
no reaction is faster than 10 s; no expedition re-dispatch is faster than ~60 s
```

**Finding.** The general literature agrees with the host's thresholds for the wrong reason and the right
conclusion: bot detection in MMO traces keys on **periodic peaks** (a WoW bot's keystroke intervals spike
at exactly 1 s and 5.5 s, its poll timers, while human intervals are Pareto-distributed — **measured**)
and on inter-action interval *standard deviation* (97% accuracy on one day of data from frequency, mean
ATI and ATI SD — **measured**). Constant or periodic work, whatever its mean, is the signature.

**As built.** Each account owns one waking window per local day, anchored to a **core wake time drawn
over 05:30–09:30** so a cohort is early birds and night owls rather than one shift, with a **nine-hour
core dark period** and up to thirty minutes of drift on either edge. The drift can only *extend* the
dark period, so the nine-hour core is present every day: eight whole wall-clock hours, which is exactly
what the host's `COUNT(DISTINCT FLOOR(time_departure % 86400 / 3600))` counts. Sessions are placed only
inside the window, and a wait that runs into the dark period is spent there rather than shortened.

**Measured, 21 simulated days per archetype** (`RoutineCadenceTest`): **16 distinct hours in the widest
seven-day window** against the host's 18, and **14 for the casual persona**; every local day covered by
a silence longer than six hours; overnight silences of 6–25 hours.

**Accept.** `RoutineCadenceTest` runs the constraint against the module's own clock rather than against
a live cohort, because the host's signals read *fleet departures* and this is the schedule that produces
them: it is deterministic, needs no running pilot, and fails the moment the routine can span the hours
the host flags. Re-run the admin page's three signals against the cohort once fleets exist ([G4](#gap--algorithm-index)),
when there is something for them to count.

### H2 — Session shape

**Shipped 14 September 2026** in `SessionPlanner`, with [H1](#h1-the-active-hours-constraint).

**Gaps:** G10 · **Host:** the schedule (`ai:run-due-work` every minute) and the session runner.

```
sessions per day:  casual 2–3, active 4–8, one hardcore persona 10–16    (analogue benchmark)
session length:    heavy-tailed, median 4–8 min, p75 ≈ 15 min,
                   plus one 45–90 min evening block, never constant      (measured analogue)
window:            primary 18:00–23:00 local, secondary 12:00–14:00,
                   sleep 01:00–08:00 local, weekend starts earlier, ends later
```

**Finding, and the reason the shape matters more than the numbers.** Human inter-event times are
**heavy-tailed, not Poisson** — email inter-event times follow a power law with exponent ≈ 1
(**measured**), and web dwell times fit a Weibull with shape `k < 1` on 98.5% of pages, per-category
median ≈ 0.65–0.80 (**measured**). A uniform or exponential gap distribution is therefore wrong in
principle: it has bounded support and no long tail. Draw session lengths and inter-action gaps from a
log-normal or Weibull with `k ≈ 0.7–0.9`, and **derive the next wake from state (S3), not from the last
wake**.

**As built.** The next wake needs no second mechanism: it is *now + a Weibull wait at shape 0.8*, and a
session's length is the same draw at the session's own scale. The wait is what the persona's visits per
day imply (`waking minutes ÷ visits − session minutes`), so the shape is a person's and the rate is the
persona's. Two rules keep the tail from doing damage: a wait never skips a waking day, and the wait that
runs into the dark period is **spent** — the account looks in again after waking, on a fresh draw, rather
than after the overflowed one, which skipped the day it had just woken into.

**Measured, 21 days per archetype** (`RoutineCadenceTest`): **2.76** visits a day for the casual
persona, **4.62–6.90** for trader, turtle and miner, **12.05** for the fleeter — inside the benchmark's
bands above; session length **median 5–8 min, p75 14–19, p90 21–32, longest 40–118 min**.

**The persona targets are calibrated, not guessed.** A persona lands *above* its target visits, because
the longest waits are spent asleep and never appear as a wait inside the day: with the benchmark's band
centres as targets the measured rate came out 1.2–1.5× high, so the targets were set from the
measurement (`RoutineProfile::sessionsPerDay` 2, 4, 5, 5, 11 for casual, trader, turtle, miner,
fleeter). The band assertion in `RoutineCadenceTest` is the check, not the target.

**What is deliberately not here.** The window is one block per day, not the benchmark's evening-plus-lunch
shape: with two to twelve visits a day the gaps themselves place the visits across the day, and a second
block would be a mechanism with nothing to do. Weekend drift and the occasional 03:00 login
([H5](#h5-what-makes-a-schedule-look-worse) 4) are likewise not modelled yet; the day's drift is what
keeps the boundary from being a square wave.

### H3 — Absence

**Shipped 14 September 2026** in `SessionPlanner`, with H1 and H2.

**Gaps:** G11 · **Host:** the inactivity thresholds (`isInactive()` = 7 days, `isLongInactive()` = 28
days, both computed from the last-activity stamp) and the vacation rules (48-hour minimum, and nothing
can be in flight).

```
per month:   1–3 single-day gaps
per quarter: one 3–7 day gap
per year:    at least one gap of 7 days or more (this does set the inactive marker — humans do this)
never:       more than ~28 days without a decision, because that is where neighbours write an account off
```

**Rule.** The absence model feeds the save duration ([V5](#v5-the-save-is-a-plan-and-the-plan-is-visible)),
the storage trigger ([E3](#e3-storage--the-fill-time-trigger)) and the reservation ([SP5](#sp5--reservation-before-spending)).
Vacation mode is *not* a cover for an absence: it freezes production and is visible, so an account that
plans to play does not enter it.

**As built.** An absence is decided where the account decides whether to come back at all: once per
waking day, as the night ends, one draw picks between coming back, a single day away, a three-to-seven-day
gap, or a week or more. The plan states its bands per month, quarter and year, so they arrive as per-day
rates — 8% of nights for the single day, 1.4% for the multi-day gap, 0.27% for the week — and nothing is
drawn above ten days, well inside the four weeks that writes an account off. Vacation mode is not used,
for the reason above.

**Measured, five years of one account** (`RoutineCadenceTest`): **106 single days, 1.8 a month** against
the band's 1–3; five or more three-to-seven-day gaps; at least one gap of a week or more; **no two-day
gap at all**, because the bands have none; longest absence ten days against the ceiling of twenty-eight.

**The absence lives in the schedule, not in a second model.** `AiSchedule::next_due_at` already holds the
return, so the save duration, the storage trigger and the reservation read the account's own plan instead
of a parallel record of it that can drift from the schedule.

**What is deliberately not here.** Absences are not planned in advance and are not visible as intent: an
account does not announce a holiday, it simply is not there, which is what a player sees.

### H4 — The activity marker is a side effect, never a ping

**Shipped 14 September 2026** as a constraint with its acceptance test, and it is the answer to
[AG3](#ag3--request-and-activity-footprint) as well.

The 15-minute per-planet marker is refreshed by *any* planet-context request, including another
player's probe on that body (**host**). **Rule:** the module never writes activity to make an account
look alive; the marker moves because real work happened on that planet. A "keep-alive" ping is a tell,
and the marker's own 15/60-minute thresholds make a uniform pattern readable at a glance.

**As built, and why no code had to change.** The module has exactly one path to the host's activity
state: `QueueAiBuildingAction` calling `PlayerGameStateService::advance()`, which is the same page-load
path a human's own click takes. A marker that moves therefore always means work was done, and a session
that decides nothing leaves it alone — which is now stated as a test rather than as an intention
(`AiActivityMarkerTest`): a session with nothing to queue leaves `users.time` exactly where it was, and
a session that queues a building moves it, through the host. There is no keep-alive in the module, and
that test is what makes adding one fail.

**The AG3 decision this settles.** The stamp is *accepted* rather than bypassed. It rides real work
inside the routine's waking window, so `isInactive`, the inactive-deletion scheduler and the galaxy
marker keep reflecting the account, and the account cannot show activity while doing nothing — which is
the mirror of the rule, from the other side.

### H5 — What makes a schedule look worse

1. **Jitter on a fixed base.** `sleep(base + rand(0, base/2))` — the pattern in several surveyed bots —
   leaves a spectral peak at `base`, which is the Botcraft failure mode exactly.
2. **Uniform jitter where humans are heavy-tailed.**
3. **The same first action in the same order every session.** Sequence self-similarity is the largest
   unclosed authenticity gap in the register (G14) and it will not be fixed by timing.
4. **A crisp sleep window.** 01:00:00–08:00:00 every day is a square wave; bed and wake times drift, and
   an occasional 03:00 login is *more* human, not less.
5. **Answering everything.** A 100% reaction rate is the aggregate version of the fixed period, and the
   host's own signal-3 threshold assumes humans miss things.
6. **Concentrated departure hours**, which is precisely what the host's `active_hours` count aggregates.

### H6 — Suspension gate

**Shipped 14 September 2026** in `PlayerObservationService`.

**Gaps:** O2 · **Host:** `PlayerService::isBanned()` and `isInVacationMode()`; today only the building
action asks, and the session path does not.

**Rule.** The observation path asks both before it publishes anything, so a suspended account offers no
capability, records `DoNothing` and touches nothing — the smallest possible gate, one host call and an
empty capability map, reusing the "no capability published" behaviour the first pilot already proved.

**The deliberate difference from the register's wording.** The register asked for the account to *stop
scheduling* while suspended. It does not: the successor session is still scheduled, because nothing else
would wake the chain when a three-day ban expires, and the register's version would have turned a
temporary ban into permanent dormancy. What a player can observe — an account that decides nothing and
actions nothing while suspended — is fixed; the bookkeeping churn of a *permanently* suspended account
is O3/L2's problem, where account states are named.

---

## Lifecycle and retention

### L1 — Retention

**Shipped 14 September 2026** in `PruneAiRecordsAction` and the nightly `ai:prune`.

**Gaps:** O1, O4 · **Host:** the host prunes only inbox messages and chat messages (7 days) and has a
weekly debris reset; **nothing** prunes `espionage_reports`, queues, traces or work items.

**Rule.** One prune command, one schedule entry, and a declared retention per table sized for the
2 vCPU / 2 GB profile: work items and receipts at 90 days, traces at 30 days, observations at 30 days,
reports whatever the persona's intel age allows (nothing longer than the host's own message window),
sealed replies at 30 days, memory fact validity handled by the `expires_at` filter that already exists.
Sizing is by rows-per-account-per-day measured in the pilot, not by a guess (the pilot already showed
76 work items, 48 traces and 18 receipts in about seven hours for eleven accounts — **measured**).

**As built.** One command, one nightly entry, and one sweep per table, over a single
model-to-window map: work items and receipts at ninety days, traces, observations and sealed replies at
thirty. The window is checked against `created_at` rather than `expires_at`, because every one of those
rows already carries its own `expires_at` for *validity* — a trace stops being evidence after thirty
days — and a validity filter keeps a row out of every query while leaving it on disk for good.

**What is not pruned, on purpose.** The account's memory: relationships, commitments, memory facts,
emotional episodes, social exchanges and experience cases. Facts keep the `expires_at` filter the plan
assigns them; the rest are what the account knows rather than what it logged. Usage reservations,
language requests and the score samples AG2 records are also left alone, and **that is the correction
[O4](GAP-REGISTER.md) asked for**: the claim is no longer "bookkeeping is bounded" but "these five
tables are bounded by time, and these others are bounded by nothing yet".

**Sized from the pilot, and stated as a steady state.** The pilot's 76 work items, 48 traces and 18
receipts in seven hours for eleven accounts are about one work item, two thirds of a trace and a
quarter of a receipt per account per hour. At the ninety- and thirty-day windows that is a steady state
of roughly **2,100 work items, 500 receipts and 450 traces per account**, and a nightly sweep that is
one indexed delete per table rather than a job per row.

### L2 — Account states

**Shipped 14 September 2026** in `AiAccountState` and `AccountStateResolver`.

**Gaps:** O3 · **Host:** planet enumeration, `isNewbie`/`isStrong`, the deletion scheduler.

`active` / `suspended` (banned or vacation) / `empty` (no planets — a destroyed or never-provisioned
account) / `final` (deleted or unreachable). `empty` is **not** "idle": it disables the account's
scheduling and records the state, so an account with no planets does not sit in a loop deciding nothing.
This is the change that turns a silent null return into a stated fact.

**As built.** One enum of four states and one resolver that derives it from host facts alone, in this
order: a player row the host no longer has is `final` — asked before the host service is loaded, which
would throw — the host's own ban and vacation flags make an account `suspended`, an account with nothing
to play is `empty`, and everything else is `active`. The observation publishes the state, so it is a
stated fact rather than an exception or a null discovered deep inside a planner, and both states with
nothing to come back to stop the chain: the session still records its decision, and no successor is
scheduled.

**Why `empty` stops and `suspended` does not.** Both mean "cannot act now", and only one of them ends by
itself. A ban and a vacation have an expiry the host owns, so the chain must keep waking to notice it
([H6](#h6--suspension-gate)); nothing in the module ever wakes an account that is gone for good, so
stopping is the honest end of that account's chain and the published state is what tells an operator why
the account went quiet.

### L3 — Budgets are enforced, not claimed

**Gaps:** O4 · **Host:** none.

The plan claims "bounded" bookkeeping; only the stop counters are actually bounded. Either the other
tables get a bound or the claim is corrected; the register records this as open, and the honest reading
is that [L1](#l1-retention) is the bound.

**Closed 14 September 2026 by [L1](#l1-retention), in the form the sentence above already chose: the
claim was corrected.** Work items, receipts, traces, observations and sealed replies are now bounded by
time, and nothing claims the rest are — usage reservations, language requests and the score samples
AG2 records stay unbounded until a measurement says otherwise, which is a smaller and truer statement
than "bookkeeping is bounded".

### L4 — Alerting is optional and an operator decision

**Gaps:** O5 · **Host:** none. A population that goes quiet is otherwise visible only in the pilot
report. Recommend: one summary line per day in the existing report, no new subsystem.

---

## Identity at provisioning

### P1 — Provisioning identity

**Gaps:** I1–I8 · **Host:** the seeder, the users table, the naming surfaces.

**Rule.** One provisioning path that produces per-account identity rather than cohort identity: a
plausible unique address (no reserved TLD), per-account dark matter inside the persona's band, creation
dates staggered across days rather than seconds, **uncorrelated** seeds (not `SEED_BASE + index`),
planet and player names from a name pool assigned at provisioning with occasional renaming later, the
`NAME_PREFIX` constant deleted or gated behind the test flag, and an explicit decision on `last_ip`
(see [AG3](#ag3--request-and-activity-footprint)). The point is not cosmetic: a spy report on two identical
planets is the view that reveals a cohort, and the register's wave-2 findings were all visible at rest.

---

## Social

### SOC1 — Speaking first

**Gaps:** G12 · **Host:** `ChatService::sendDirectMessage` (permission is only "not ignored"; the
length, self-send and existence checks live in the controller), the authored-dialogue path, the delivery
ledger.

**Rule.** Initiation is rare, contextual and sourced: a report share after a raid, a thank-you after a
received transport, a greeting to a neighbour who probed us, an alliance application. Every initiation
goes through the same authored-dialogue-first path as a reply and carries the reason it was sent. The
aggregate constraint matters more than any single message: the module's **social action entropy** must
be spread over the kinds a human uses — measured bot-versus-human entropy in Aion was 0.43 versus 0.84
over seven interaction types, with bot party degree 1.4 versus human 25.4 (**measured**) — and a
"contact only when task-shaped" account has an entropy near zero.

### SOC2 — Alliance life

**Gaps:** G18, S1, S2 · **Host:** `AllianceService`, `AllianceApplication`, `ChatService::sendAllianceMessage`
/ `getAllianceMessages`, and the existing membership observer.

**Rule.** Apply (talking to a member first, as the alliance FAQ describes — **documented**), and then
behave like a member: answer alliance chat, which the observer currently skips entirely, and act on
committed alliance context. Entering an alliance while answering nobody is worse than never joining,
which is why S1 and S2 are one algorithm and not two. The joining decision itself is a scope question
for the owner, recorded in the register and not assumed here.

### SOC3 — The social surfaces that are ignored today

**Gaps:** S3 · **Host:** `BuddyService` (requests, ignored players), `NoteService`, the alliance
application, and the Dark-Matter merchant.

**Rule.** Decide per surface, then handle only the decided ones: an alliance application is worth
answering, a buddy request is worth accepting from a contact who already exists, a note is private and
needs no answer, and the merchant has no offers to answer. Silence on an invitation is a tell only if
the account is otherwise social; the register's rule is to decide, not to instrument everything.

### SOC4 — Delivery stays the host's

Nothing here generates text on the ordinary path; the authored-dialogue library and the
permission-checked delivery ledger already own this, and the plan's "provider off by default" decision
is unchanged.

---

## Transports and trade

### X1 — Transfers between own planets

**Shipped as `QueueableTransferPlanner` / `QueueAiTransferAction`** — a colony short of its next
level's cost is funded from the body that can spare it, netting in-flight transports and keeping the
source's SP5 reserve.

See [E4](#e4-ferrying-resources-between-own-planets); the same transport mission serves it, and the
reservation in [SP5](#sp5--reservation-before-spending) is what keeps a transfer from starving the account.

### X2 — Trade

**Gaps:** G17 · **Host:** **there is no marketplace, no trade request and no resource exchange**
(**host**, stated plainly). The only exchange in OGameX is the Dark-Matter merchant
(`MerchantService::callMerchant()`, 3,500 dark matter) with its generated rates.

**Consequences, stated as decisions.**

1. "Trade" in this game is a **transport** at an agreed ratio, so the trader persona expresses itself
   through transport volume and timing, not through a market.
2. The rules constrain it: trades, recycling help and ACS splits must complete within **72 hours**, and
   manipulation of trade ratios for a higher-ranked account is pushing and can be banned
   (**documented**, rules §5). Note the honest limit: no page states an explicit legal ratio window
   (e.g. "2:1:1 to 3:2:1"), so the module uses the accepted band as a *self-imposed* constraint and says
   so.
3. No dedicated trader playstyle source exists (**finding**), so the trader persona is module-defined
   taste over transport behaviour and must not claim a source it does not have.

---

## Aggregate shape and footprint

### AG1 — Per-account divergence

**Gaps:** A1(reg), G13, G14 · **Host:** the persona seed and the host numbers.

Two accounts on the same host data must not converge. The mechanisms that produce divergence are the
skill band (E1's horizon), the risk band (T2's tolerance), the reaction band (V2), the cadence
parameters (H2) and the opening taste (E5). This is why the persona work is not cosmetic: with one
executable action every account queued the same thing (**measured, in the pilot**).

### AG2 — The growth curve is ours to record

**Gaps:** A2(reg), A3 · **Host:** `highscores` carries *current* points only — **there is no history
table** (**host**), so "the public hourly growth curve" cannot be read from the schema.

**Rule.** The module records its own hourly points series (economy, research, military built, military
lost, general) per account from the host's score service, and the register's rank-trajectory question
(entry rank, slope, spread; the coefficient of variation of daily growth) is answered from that series.
Without it, signals 3 and 4 are unmeasurable, and "the plan audits a signal it cannot see" is exactly the
failure the register's root causes name.

### AG3 — Request and activity footprint

**Gaps:** A4(reg), A5 · **Host:** `users.time` is written by `PlayerGameStateService::advance()`, which
the module **already calls** — and it stamps `last_ip` from the ambient request context, which for a
queue or CLI run is empty or loopback (**host**).

**Correction to the register.** A5's claim that "nothing in `app/` touches `users.time`" is false
transitively: the module's own action path already stamps last-activity. **Rule:** make it a decision.
Either accept the stamp (in which case it must be *shaped* like H2's session model rather than
incidental) or bypass it (in which case `isInactive`, the inactive-deletion scheduler and the galaxy
marker all stop reflecting the account). The recommendation in this file is to accept it and derive it
from the routine, because an account that acts while showing no activity is the anomaly.

### AG4 — Footprint honesty

**Gaps:** A4(reg) · The module makes no HTTP requests: its work is scheduled server-side. Either signal 8
is corrected to "no fabricated page cadence; the observable footprint is the activity marker and the
schedule", or a page-like cadence is designed. The evidence points at correcting the signal: the host's
own detector reads *departures and hours*, not page loads (**host**), so a fabricated cadence would add
risk without addressing a measured signal.

---

## Delivery order

The order below is the gap register's sequence, with each step naming the algorithm sections it lands.
Every step ships with the module gate green — Rector, Pint, PHPStan level 8, full Pest, 100% PCOV — and
with its acceptance evidence recorded.

1. **3P — economy by host numbers** (E1, E2, E3, E5). Deletes `FirstBuildingTarget`, the scoring-policy
   layers and `BUILDING_WEIGHTS`. **Shipped.**
2. **Host obligations round 1** ([the capability map](../research/host-capability-map.md),
   [the change request](host-change-request.md)): the queue-upgrade predicate, vacation-mode on add, the
   recall ownership check, expedition hold bounds. Module-side, these replace `AiBuildingMachineName`.
3. **Routine and absence** (H1, H2, H3, H4, H6, L2, L3) — independent of the executors and the largest
   single authenticity gain, because the host's own detector gives the acceptance test. **Complete**:
   H1, H2, H3, H4 and H6 shipped as themselves, L2 as account states, L3 as L1.
4. **Research** (R1, R2, R3) — unlocks every later capability and needs no new host support.
5. **Units and cargos** (U1, U2, U4, A1, A2(reg)) — military points stop being zero, the ledger exists.
6. **Fleets and saving** (V1–V5, H3) — the fleet exists, so the save can exist, and the failed save is
   finally expressible.
7. **Intelligence** (N1–N3) — probes, target lifecycle, staleness.
8. **Raiding and estimation** (T1–T5) — needs the host's battle entry point decision first; until a
   read-only simulation path exists, raids remain recorded intents with a stated reason.
9. **Colonies** (CL1, CL2) — astro-driven, one more mine.
10. **Social** (SOC1–SOC4, X1, X2) — after there is something to talk about.
11. **Identity** (P1) and **aggregate shape** (AG1–AG4) — provisioning and measurement.
12. **Lifecycle** (L1, L4) with the pilot's measured volumes.

### Post-Package-4 enrichment (proposed, blocked on catalog review)

The wave-6 strategy gaps are depth, not capability: the host already supports every seam, the module
does not reach them. Proposed order, each slice gated by the module gate and a reviewed cluster:

13. **The activity and intel reader** (SP7) — one read-only reader unblocks T6, T8, N4 and V6.
14. **Raid depth** (T6, T7, T8) — target score, tiered gate, cargo sizing, launch re-check.
15. **Intelligence depth** (N4, N5) — spy target score, own signature control.
16. **Save depth** (V6, V7, V8) — proactive save, route scoring, masking, shadow waves.
17. **Fleetcrash** (F1–F6) — phalanx, recall, moon, recycle, blind lanx, moon destruction; only
after 13–16 and only when the cluster is reviewed (Pass-4 niche).
18. **Fleet composition** (U-series) — FLE-002/004/009/010/011 composition, counters and recycler
sizing; the next increment, not this one.

## What we refuse to build

Each refusal is a finding from this pass, not a preference.

- **A combat implementation, in PHP or borrowed.** A Rust/WASM re-statement of OGameX's own formulas
  exists publicly (**found**); writing its PHP twin would create a second authority that drifts from the
  host, which is the duplication [AGENTS.md](../../../AGENTS.md) forbids.
- **Fleet-composition search.** The one project that optimises compositions does it with a genetic
  algorithm over a hardcoded universe and a hardcoded counter table (**verified**); our universe is
  host-supplied and unbounded, so subset search is both a gate-1 violation and combinatorially
  unbounded.
- **A mean-profit simulator output.** Documented to invert a real decision by 60 M resources.
- **A 0.95 win-probability constraint at n = 50.** Not measurable at that sample size; count losing runs.
- **Uncapped simulation counts.** "Uncapped — it is your CPU" is the opposite of a 2 vCPU budget.
- **A Bayesian defender model.** No surveyed tool does it and gate 3's answer is re-scouting.
- **Porting opbe's expected-value engine.** It is a modelling choice, not a measurement, and its author
  says to validate against thousands of runs.
- **A fixed-tick scheduler.** It is the single best-validated signature of automation in the literature
  and the first signal in the host's own detector.
- **A keep-alive activity ping.** The marker is per body and is refreshed by real work; a ping makes a
  uniform pattern (H4).
- **An "80% of fleets are lost offline" statistic.** No source; delete it wherever it appears.
- **Any object or requirement table, in any project's form.** Every project in the corpus with an
  object universe has one, and it is the one thing the survey proves we must not do; the single exception
  is a pure formula library whose costs are function parameters.
- **A `StrategicPosture` class.** The classical corpus offers a master counter (Zero Hour) and
  resource states (Wesnoth), but neither justifies a third axis over `ArchetypePolicy` + `AiSkillBand`
  until a concrete intent cannot be scored — the strategy-mining disagreement 5, kept open.
- **A behaviour-profile class hierarchy.** Freelancer's inheritance is a delta over one profile;
  `ArchetypePolicy` + `AiSkillBand` already layer that way. No profile base/derived classes.
- **A per-archetype planner matrix.** Never `MinerEasyRaidPlanner` × … — shared planners plus
  composable profile modifiers (Freelancer's simplification, OpenRA's modules).
- **Cheat difficulty.** Harder means better decisions, never hidden resources or recovery advantages
  (Freelancer's negative lesson), unless deliberately designed and named.
- **A rule/script interpreter.** Zero Hour's script VM is machinery to learn from, not to import:
  gate 2, and [`decision-techniques.md`](decision-techniques.md) already rejected rule engines.

## Corrections to earlier docs

This pass verified earlier claims and found several that must be changed or retracted. They are listed
here so the older notes can be trusted where they were right and not trusted where they were not.

| Earlier claim | Verdict | Now |
| --- | --- | --- |
| "Compare return per hour of queue" as the economy's comparison | **Unproven.** No project in the corpus computes it; five read the queue only as a boolean guard | [E2](#e2-queue-occupancy-honest-about-what-is-not-proven): tie-break, and a measured comparison before it is claimed |
| The raid "loot split" as a multi-wave fraction | **Not on any page.** Only "50% of stored resources, 75% for one class" | Retracted in [T1](#t1-the-profit-test-as-an-audit-trail) |
| "An explicit legal trade ratio band" | **Not stated.** §5 bans ratio manipulation, an explicit window is nowhere | [X2](#x2--trade): self-imposed band, labelled as ours |
| "Defence-to-value ratio" targets | **No source** | [U3](#u3-defence--unprofitability-not-ratios): computed from the observed attacker |
| A single solar-plant-to-satellite switch point | **Contested**: 16, 18, 20–26, "high twenties", 30, 32 | [Y2](#y2-fusion-satellites-and-where-the-sources-give-up): a persona band, never a constant |
| Storage "protects" resources | **Three incompatible statements** (50% lootable / 10% of daily production / warehouses immune) | [E3](#e3-storage--the-fill-time-trigger): the actionable half — above capacity is fully lootable |
| "Return 10–20 minutes after waking" | Single non-Gameforge source; Gameforge says +30–60 min | [V1](#v1-the-save-state-machine): cited as one source among three |
| `ogame.fandom.com` pages as citations | **Unreachable** (302 to an ad server) | Cite `?action=raw` or replace with Sidian/the EN board |
| `board.origin.ogame.gameforge.com` tutorials (06, 08, 09, 10, 12, 13, 15, Guide 06, Guide 10, Tactic 05a) | **Dead board**; only Tutorials 01–04 were recovered on the archived EN board | Cite the recovered URLs only |
| Register A5: "nothing touches `users.time`" | **False transitively** — `PlayerGameStateService::advance()` stamps it, and the module calls it | [AG3](#ag3--request-and-activity-footprint) |
| "No fleet observer, and the host data exists" (G8) | **Partly wrong**: `IncomingFleetIntelService` is a redactor, not an intel API | [V2](#v2-the-reaction-window), [capability map](../research/host-capability-map.md) |
| Storage enumeration covers all storage objects | `getBuildingObjectsWithStorage()` excludes stations | Recorded as a host obligation |
| "Bots have no failure model" | **Confirmed**, and no source quantifies human failure either | [V3](#v3-the-save-that-fails) keeps the placeholder honest |

## Sources

The canonical strategy knowledge — sources, principles and claims — is the YAML store at
[`../research/strategy/`](../research/strategy/README.md); the Markdown catalogs are its human
narrative. Algorithm blocks below name the principle IDs that store assigns.

Algorithms and constants: [what automation tools already solved](../research/ogame-automation-algorithms.md)
(eleven projects, with the per-project file paths); [experienced-player strategy and deterministic
simulation](../research/strategy-simulation-and-bot-patterns.md); [how experienced players actually
play](../research/veteran-play.md); [which decision technique to use](decision-techniques.md);
[the host capability map](../research/host-capability-map.md).

Published play: Gameforge's [raider guide](https://gameforge.com/en-GB/games/ogame-raider-guide.html),
[fleetsave guide](https://gameforge.com/en-GB/games/ogame-fleetsave.html) and
[happy hour](https://gameforge.com/en-GB/games/ogame-happy-hour.html); the
[official rules](https://en.ogame.gameforge.com/ajax/main/rules) (§4 bashing, §5 pushing and the 72-hour
rule, §6 scripts); the
[miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/);
the [ratios thread](https://board.en.ogame.gameforge.com/index.php?thread/715961-metal-crystal-mine-ratios/);
[OGames payback](https://ogames.net/blog/ogame-mine-ratios-payback-times) and
[energy](https://ogames.net/blog/ogame-energy-management-guide); Sidian's
[getting started](https://sidian.app/s/ogame-wiki/guides/getting-started),
[farming](https://sidian.app/s/ogame-wiki/guides/farming) and
[fleet saving](https://sidian.app/s/ogame-wiki/guides/fleet-saving); the archived EN board
[Tutorial 01](https://board.en.ogame.gameforge.com/index.php?thread/813416-tutorial-01-basic-economy/).

Classical game AI patterns: [classical-ai-patterns.md](../research/classical-ai-patterns.md)
(Zero Hour, Freelancer, OpenRA, Cobra, Wesnoth — design patterns only, no copied implementation).

Timing research: Kang & Kim 2022, *Quick and easy game bot detection based on action time interval
estimation*, ETRI J. 45(4); Gianvecchio et al., *Battle of Botcraft*, CCS'09; Barabási, *The origin of
bursts and heavy tails in human dynamics*, Nature 435 (2005); Liu, White & Dumais, *Understanding web
browsing behaviours through Weibull analysis of dwell time*, SIGIR'10; Kang et al., SpringerPlus 5:523
(2016) and the Aion dataset.
