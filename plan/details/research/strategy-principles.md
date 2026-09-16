# Strategy Principles Catalog

Created 15 September 2026 by [strategy mining](../specs/strategy-mining.md). This is the **atomic**
form of the knowledge already verified in [`veteran-play.md`](veteran-play.md),
[`gameplay-algorithms.md`](../specs/gameplay-algorithms.md) and
[`ogame-automation-algorithms.md`](ogame-automation-algorithms.md). It adds nothing the sources do not
support: every `shipped` entry is already implemented and tested; every `researched`/`gap` entry is a
documented principle the code does **not** yet express, and implementation stays blocked until review.

- **confidence** (derived from the existing provenance markers, never a parallel scale):
  **A** = mechanically/host-derivable or multi-source verified · **B** = documented (guide/wiki/official) ·
  **C** = contested/synthesis · **D** = anecdotal/placeholder.
- **status**: `shipped` (in code) · `partial` (shipped but thinner than the principle) · `deferred`
  (decided, later slice) · `researched` (validated principle, no code yet) · `gap` (needs sources).
- **ogamex**: host support per the verified surface scan (`supported / partial / unsupported`) and whether
  the module reaches it.
- **priority**: P0 formalize only · P1 highest-value gap · P2 medium · P3 deferred.

## Coverage matrix

| Domain | Sources | Principles | Shipped | Researched/gap | Coverage |
| --- | --- | --- | --- | --- | --- |
| Economy | strong | 7 | 7 | 0 | **high** |
| Research | strong | 3 | 3 | 0 | **high** |
| Colonization | strong | 4 | 1 | 3 (1 partial) | high |
| Raiding | strong | 14 | 3 | 11 | medium (sourced, unwired) |
| Fleet composition | strong | 15 | 2 | 13 (1 partial) | high (sourced, unwired) |
| Fleetsave / survival | strong | 11 | 1 | 10 (2 partial) | high (sourced, unwired) |
| Espionage / intel | strong | 12 | 1 | 11 (1 partial) | medium (sourced, unwired) |
| Fleetcrash / phalanx / moon | strong | 14 | 0 | 14 (1 partial) | medium (sourced, unwired) |
| Ninja / baiting | medium | 5 | 0 | 5 | medium (sourced, unwired) |
| ACS / coordination | moderate | 14 | 0 | 14 | medium (alliance-gated, deferred Pkg 6) |
| Expeditions | moderate | 2 | 0 | 2 | medium (sourced, unwired) |
| Routine / authenticity | strong | 4 | 3 | 1 (1 partial) | high |
| Social / diplomacy | moderate | 2 | 0 | 2 | deferred (Pkg 6) |

---

## Economy

### ECO-001 — Prefer economic investment with better amortization
- **category:** economy · **confidence:** A · **status:** shipped
- **principle:** order production upgrades by marginal gain ÷ weighted price; stop when payback exceeds the persona horizon.
- **inputs:** next-level cost, raw production gain, build time, average mine level.
- **affects:** economic investment scoring · **archetypes:** miner strong-positive, fleeter neutral.
- **exceptions:** energy bottleneck (ECO-005), imminent threat, major unlock (RES-002, COL-001).
- **sources:** FOR-001, TP-002, BOT-001 (verified) · **ogamex:** supported · **code:** `EconomyUpgrades::pending` (E1) · **priority:** P0

### ECO-002 — The payback horizon adapts and caps
- **category:** economy · **confidence:** A · **status:** shipped
- **principle:** accept paybacks up to an adaptive cap `min(168 h, 24 h × (1 + average mine level / 20))`.
- **inputs:** average mine level, persona horizon.
- **affects:** stop rule for the economy rank · **exceptions:** none.
- **sources:** BOT-001 (verified), TP-002 (documented) · **ogamex:** supported · **code:** `EconomyUpgrades::paybackHorizonHours` · **priority:** P0

### ECO-003 — Queue time is a tie-break, not a discount
- **category:** economy · **confidence:** B · **status:** shipped
- **principle:** queue time orders only near-equal paybacks and feeds the next wake; it never discounts payback (no precedent in the corpus).
- **inputs:** build time, switch margin · **exceptions:** long build chosen only when the persona is not absent longer than it.
- **sources:** BOT-002, BOT-003 (verified) · **ogamex:** supported · **code:** E2 tie-break · **priority:** P0

### ECO-004 — Storage upgrades on time-to-fill, not on a fill fraction
- **category:** economy · **confidence:** B · **status:** shipped
- **principle:** queue the storage object when remaining capacity fills before the next spend/absence; a full warehouse is fully lootable.
- **inputs:** capacity, stored, production per hour, storage horizon (48 h).
- **affects:** storage candidacy · **exceptions:** below the energy factor the planet never fills — do not offer storage.
- **sources:** TP-010, TP-008 (documented); BOT-002/BOT-001 thresholds disagreed (verified) · **ogamex:** supported · **code:** E3 · **priority:** P0

### ECO-005 — Build energy capacity before the level that would outdraw the planet
- **category:** economy · **confidence:** C · **status:** shipped (predictive doctrine)
- **principle:** if the balance is negative, or the next production level would make it negative, build the cheapest capacity first.
- **inputs:** energy balance, next-level energy cost, energy output of capacity objects.
- **affects:** energy candidacy before mines · **exceptions:** the deficit doctrine is contested; the module chooses predictive because an unrepaired deficit is the one thing ordinary play never looks like.
- **sources:** TP-003, ORG-002, BOT-004 (verified, contested) · **ogamex:** supported · **code:** `EnergyCapacity` (Y1) · **priority:** P0

### ECO-006 — The plant→satellite switch is a persona band, never a constant
- **category:** economy · **confidence:** C · **status:** shipped
- **principle:** fusion appears only when deuterium is surplus; satellites are destructible units; the switch level (16/18/20–26/30/32) is contested, so it is a band over host-quoted cost vs output.
- **inputs:** plant cost, satellite output, temperature · **exceptions:** none.
- **sources:** FOR-003, FOR-004, TP-003 (contested) · **ogamex:** supported · **code:** Y2 · **priority:** P0

### ECO-007 — Value resources in the accepted trade band
- **category:** economy · **confidence:** A · **status:** shipped
- **principle:** reduce all costs to one currency with `M + 1.5 C + 2 D` (or the same idea as `3:2:1`).
- **inputs:** resource amounts · **exceptions:** the band is planning preference, not a guaranteed rate.
- **sources:** BOT-002, BOT-001, BOT-003 (verified), TP-002 (documented) · **ogamex:** supported · **code:** `EconomyUpgrades` weights · **priority:** P0

## Research

### RES-001 — Research only when it out-pays the last purchase
- **category:** research · **confidence:** A · **status:** shipped
- **principle:** a research is taken when its payback beats the payback of the last mine started (14-day decay), capped at the persona horizon; otherwise keep mining.
- **inputs:** research payback, last purchase payback, horizon · **exceptions:** capability unlocks (RES-002).
- **sources:** BOT-003 (verified) · **ogamex:** supported · **code:** R1 · **priority:** P0

### RES-002 — A capability research scores through what it unlocks
- **category:** research · **confidence:** A · **status:** shipped
- **principle:** a research with no income of its own is priced by the cheapest object it makes reachable; the mine↔astrophysics roadmap is a derived consequence, never a table.
- **inputs:** requirement graph, cheapest unlockable object · **exceptions:** none.
- **sources:** BOT-003 (verified), FOR-001 (documented) · **ogamex:** supported · **code:** R2 · **priority:** P0

### RES-003 — Research order is derived from ROI, not a cap table
- **category:** research · **confidence:** B · **status:** shipped
- **principle:** "treat them like they are mines — build whatever has the shortest return on investment time"; no per-technology cap list (that is gate-1 forbidden).
- **inputs:** ROI per technology · **exceptions:** none.
- **sources:** FOR-001 (documented); BOT-003 caps rejected (verified) · **ogamex:** supported · **code:** R2 · **priority:** P0

## Colonization

### COL-001 — Choose the slot by the host's own position bonuses
- **category:** colonization · **confidence:** A · **status:** shipped
- **principle:** slot choice reads host-published position bonuses (crystal 40/30/20% on slots 1/2/3, metal 35% on slot 8, deuterium maximised on slot 15); the earlier "30/22.5/15" figures are stale official values, not a genuine contest — the host's published table is authoritative (gate 1).
- **inputs:** host position bonuses, available slots · **exceptions:** none.
- **sources:** WIK-012, TP-016 (host 7.4.0/7.5.0 values), ORG-010 · **ogamex:** supported · **code:** `QueueableColonyPlanner` (CL1) · **priority:** P0

### COL-002 — Treat the colony as another mine
- **category:** colonization · **confidence:** B · **status:** partial
- **principle:** a new colony's value is the production it adds over cost — the same payback logic as a mine, not a one-off step.
- **inputs:** colony cost, position yield · **exceptions:** none.
- **sources:** veteran-play §8 (documented) · **ogamex:** supported · **code:** CL2 (next economy step) · **priority:** P2

### COL-003 — The first colony is early and deuterium-biased
- **category:** colonization · **confidence:** B · **status:** researched
- **principle:** the first colony lands in week 2 at an outer slot (12–15) for deuterium, and Astrophysics is rushed to 3 for a second colony because each new colony doubles income potential.
- **inputs:** astro level, slot bonuses, deuterium demand · **exceptions:** none.
- **sources:** TP-007 (documented) · **ogamex:** supported (host slot bonuses) but unwired · **code:** none · **priority:** P2

### COL-004 — Spread for coverage, keep one same-system pair
- **category:** colonization · **confidence:** C · **status:** researched
- **principle:** spread colonies across systems/galaxies for phalanx coverage, farming radius and resilience, keep one same-system pair for the cheapest moon-to-moon save, and abandon colonies below ~200 fields.
- **inputs:** positions, field counts, travel cost · **exceptions:** none.
- **sources:** TP-016 (synthesis) · **ogamex:** supported but unwired · **code:** none · **priority:** P2

## Raiding

### RAID-001 — Profit is the gate, not loot
- **category:** raiding · **confidence:** A · **status:** shipped
- **principle:** "Profit = Loot − Deuterium − expected ship losses"; loot ≥ 3× deuterium, losses ≤ 20–30% of loot; a negative raid is a donation.
- **inputs:** loot, deuterium, expected losses · **exceptions:** none.
- **sources:** GF-001 (documented) · **ogamex:** supported · **code:** `RaidPlanner::plan` + `NativeRaidEstimator` (T1–T3) · **priority:** P0

### RAID-002 — Respect the host's bashing rule exactly
- **category:** raiding · **confidence:** A · **status:** shipped
- **principle:** ≤6 attacks per target per 24 h; destroyed attacking fleets do not count (the module reads the host answer rather than its own counter).
- **inputs:** attack count per target per window · **exceptions:** war, probe/missile attacks.
- **sources:** GF-005 §4 (documented) · **ogamex:** supported · **code:** `RaidPlanner::withinBashingLimit` · **priority:** P0

### RAID-003 — Use a conservative tail statistic, never a mean
- **category:** raiding · **confidence:** A · **status:** shipped
- **principle:** order raids by a lower quantile (P20) and count losing runs; a documented mean-profit reading inverted a 60 M decision.
- **inputs:** net-profit sample distribution · **exceptions:** none.
- **sources:** SIM-002 (verified failure), SIM-001 (methodology) · **ogamex:** supported · **code:** `NativeRaidEstimator::lowerQuantile` (T2) · **priority:** P0

### RAID-004 — Recent target activity raises interception risk
- **category:** raiding · **confidence:** B · **status:** researched
- **principle:** activity observed close to flight raises escape/interception risk and should reduce expected raid utility.
- **inputs:** target `time_last_update`, activity star state · **exceptions:** inactives/farms.
- **sources:** TP-006 (documented), veteran-play §7 · **ogamex:** supported (host `Planet.time_last_update`, galaxy activity status) but **unwired** · **code:** none · **priority:** P1

### RAID-005 — Intel freshness decays per information type
- **category:** raiding · **confidence:** B · **status:** researched
- **principle:** resources/fleets go stale quickly, coordinates/losses slowly; confidence is per-type, not a flat `1.0`.
- **inputs:** report age, information type · **exceptions:** none.
- **sources:** decision-policies.md, TP-006 (documented) · **ogamex:** supported but flat (`confidence = 1.0` hardcoded in `PlayerObservationService::targetReports`) · **code:** none · **priority:** P1

### RAID-006 — Profit must carry travel and slot opportunity cost
- **category:** raiding · **confidence:** B · **status:** researched
- **principle:** subtract fuel, occupied fleet slots and travel time from expected profit; `travel_cost` is currently a placeholder `0.0`.
- **inputs:** distance/fuel, slot occupancy, duration · **exceptions:** none.
- **sources:** GF-001, BOT-005 (route enumeration) · **ogamex:** supported (host fuel/duration quotes) but unwired · **code:** `travel_cost` placeholder · **priority:** P1

### RAID-007 — Personality and relationship shape raid utility
- **category:** raiding · **confidence:** C · **status:** researched
- **principle:** a fleeter raids more, a turtle/miner less; a past ally or debtor is raided differently — the archetype gate exists at action level but never reaches target scoring.
- **inputs:** archetype, relationship state · **exceptions:** none.
- **sources:** FOR-007, persona research · **ogamex:** partial (relationship tables exist; not read by `RaidPlanner`) · **code:** none · **priority:** P2

### RAID-008 — Score-ratio gate for target viability
- **category:** raiding · **confidence:** B · **status:** researched
- **principle:** a target scoring under ~⅕ of yours cannot defend economically against your fleet class — a pre-profit filter, not a substitute.
- **inputs:** target score vs own, fleet class · **exceptions:** none.
- **archetypes:** fleeter strong · **sources:** TP-004, GF-001 · **ogamex:** supported (public scores) but unwired · **code:** none · **priority:** P2

### RAID-009 — Cluster targets by proximity; raid on a schedule
- **category:** raiding · **confidence:** B · **status:** researched
- **principle:** build a farm list clustered by galaxy proximity (cross-galaxy ≈ 5× deuterium) and hit on a storage-fill schedule (8–12 h), not ad hoc nearest-target.
- **inputs:** coordinate distance, storage fill time · **exceptions:** none.
- **archetypes:** fleeter strong · **sources:** TP-004, TP-008 · **ogamex:** supported (distance/fuel quotes) but unwired · **code:** none · **priority:** P1

### RAID-010 — Activity dot at launch is an abort/delay signal
- **category:** raiding · **confidence:** B · **status:** researched
- **principle:** a flashing activity dot on the target at flight time means it may have just logged in — abort or delay rather than fly into a recall/ninja.
- **inputs:** target `time_last_update` at dispatch · **exceptions:** none.
- **archetypes:** fleeter strong · **sources:** TP-004, TP-006 · **ogamex:** supported (activity marker) but unwired · **code:** none · **priority:** P1

### RAID-011 — Profit threshold is tiered by risk
- **category:** raiding · **confidence:** B · **status:** researched
- **principle:** ≥3:1 loot-to-fuel on routine farms, ≥2:1 on defended targets with debris, <1.5:1 marginal — stricter than the single 3× figure when debris subsidises the run.
- **inputs:** loot, fuel, debris, target defence · **exceptions:** none.
- **archetypes:** fleeter strong · **sources:** TP-004, GF-001 · **ogamex:** supported (host fuel/debris) but unwired · **code:** none · **priority:** P2

### RAID-012 — Size cargo to the report, with a buffer
- **category:** raiding · **confidence:** B · **status:** researched
- **principle:** cargo count = expected loot (50%/75% of visible) ÷ capacity, +20% buffer; under-cargoed raids waste already-sunk fuel.
- **inputs:** report resources, class loot multiplier, cargo capacity · **exceptions:** none.
- **archetypes:** fleeter strong · **sources:** TP-004, TP-008 · **ogamex:** supported but `FIRST_CARGO_AMOUNT = 1` fixed · **code:** `QueueableUnitPlanner` · **priority:** P1

### RAID-013 — Popular farms are contested; win by proximity or timing
- **category:** raiding · **confidence:** B · **status:** researched
- **principle:** well-known inactives are cleaned out fast; the edge is being first (proximity) or hitting on a schedule others miss — novelty is a schedule edge, not an unexplored target.
- **inputs:** target popularity, galaxy density, competitor activity · **exceptions:** none.
- **archetypes:** fleeter strong · **sources:** TP-004 · **ogamex:** supported but unwired · **code:** none · **priority:** P2

### RAID-014 — Profit = loot − fuel − losses; debris is a separate trip
- **category:** raiding · **confidence:** C (contested) · **status:** researched
- **principle:** module doctrine keeps debris OUT of the single-raid gate (two missions, two capacities); the published formula includes it (`Loot + Debris − Fuel − Losses`). Record the split, don't resolve silently.
- **inputs:** loot, fuel, losses, debris · **exceptions:** none.
- **archetypes:** fleeter · **sources:** RAID-001/CRASH-001 (module), TP-004 (includes debris) · **ogamex:** supported · **code:** `NativeRaidEstimator` (debris excluded) · **priority:** P2

## Fleet composition

### FLE-005 — Fodder absorbs shots and denies rapid fire
- **category:** fleet composition · **confidence:** A · **status:** researched
- **principle:** every unit kills at most one enemy per shot, so cheap fodder hulls absorb random targeting and suppress the enemy's rapid fire against your heavies; heavy hulls need a fodder screen.
- **inputs:** opponent hull mix, rapid-fire graph, fodder:heavy ratio · **exceptions:** none.
- **archetypes:** fleeter strong-positive · **sources:** WIK-004, ORG-008 (Wayback) · **ogamex:** supported (host combat engine + rapid-fire data) but unwired · **code:** none · **priority:** P1

### FLE-006 — Light fighter is the durable fodder; heavy fighter is early-only
- **category:** fleet composition · **confidence:** B (HF early choice contested) · **status:** researched
- **principle:** light fighters are the cost-effective fodder and become a raid ship once targets carry plasma; heavy fighters age out once gauss/plasma appear.
- **inputs:** target defence composition, stage · **exceptions:** heavy fighter vs rocket/light-laser-heavy defence early.
- **archetypes:** fleeter · **sources:** ORG-008 (Wayback), GF-001 · **ogamex:** supported but unwired · **code:** none · **priority:** P1

### FLE-007 — Counters are the host rapid-fire graph, never remembered
- **category:** fleet composition · **confidence:** A · **status:** researched
- **principle:** pick hulls with rapid fire against the opponent's mix and deny it against your own; the graph is host data (P_repeat = (r−1)/r), never module memory.
- **inputs:** opponent mix, per-unit rapid-fire attributes · **exceptions:** none.
- **archetypes:** fleeter · **sources:** WIK-004 · **ogamex:** supported (host unit data) but unwired · **code:** none · **priority:** P1

### FLE-008 — Composition tracks universe stage and targets, not a fixed ratio
- **category:** fleet composition · **confidence:** C (stage bands) / B (official early band) · **status:** researched
- **principle:** rebuild toward what makes current targets profitable (raiding vs crashing vs turtles); stage bands are a shape, not a table.
- **inputs:** universe age, typical target defence, target hull mix · **exceptions:** none.
- **archetypes:** fleeter · **sources:** ORG-008 (Wayback), GF-001 · **ogamex:** supported but unwired · **code:** none · **priority:** P1

### FLE-009 — Recyclers are sized to clear your own debris alone
- **category:** fleet composition · **confidence:** B · **status:** researched
- **principle:** keep enough recyclers to harvest a solo hit's debris without alliance help; the count tracks your heavies and expected debris, not a constant.
- **inputs:** expected debris, recycler capacity, alliance recycling · **exceptions:** socialized recycling ceiling.
- **archetypes:** fleeter strong-positive · **sources:** ORG-008 (Wayback), GF-001 · **ogamex:** supported (`DebrisFieldService`, `RecycleMission`) but unwired · **code:** none · **priority:** P1

### FLE-010 — Cargo is sized to payload, not a fixed one
- **category:** fleet composition · **confidence:** B · **status:** researched (refines FLE-002)
- **principle:** small-cargo scales with raid follow-up, large-cargo with held resources; neither is 1.
- **inputs:** payload size, held resources, cargo capacity · **exceptions:** first cargo before any fleet.
- **archetypes:** fleeter/raider · **sources:** ORG-008 (Wayback) · **ogamex:** supported but `FIRST_CARGO_AMOUNT = 1` fixed · **code:** `QueueableUnitPlanner` · **priority:** P1

### FLE-011 — The debris role is harvester-by-class
- **category:** fleet composition · **confidence:** A (host) / B (reaper/General) · **status:** researched
- **principle:** recyclers harvest the field, reapers auto-collect after a won battle, pathfinders are the alternate debris-capable hull; the debris path is separate from the raid path.
- **inputs:** debris amount/coordinates, recycler count, class bonus · **exceptions:** none.
- **archetypes:** fleeter; General class strong-positive · **sources:** host-capability-map, GF-001 · **ogamex:** supported (`DebrisFieldService::calculateRequiredRecyclers`, `RecycleMission`) but unwired · **code:** none · **priority:** P1

### FLE-001 — Derive roles from host unit properties
- **category:** fleet composition · **confidence:** A · **status:** shipped
- **principle:** cargo/defence/escort/colony/probe roles come from host unit properties (capacity, attack) — never a hardcoded ship list.
- **inputs:** unit properties, requirements, price · **exceptions:** none.
- **sources:** gate 1 reference, BOT corpus · **ogamex:** supported · **code:** `QueueableUnitPlanner` (U1/U3) · **priority:** P0

### FLE-002 — Size cargo to the payload it must carry
- **category:** fleet composition · **confidence:** B · **status:** partial
- **principle:** cargo quantity follows raid payload/colony cost, not a fixed `1`.
- **inputs:** payload size, cargo capacity · **exceptions:** first cargo while no fleet exists.
- **sources:** GF-001, FOR-001 (documented) · **ogamex:** supported · **code:** `FIRST_CARGO_AMOUNT = 1` fixed · **priority:** P1

### FLE-003 — Defence exists to make attack unprofitable, not to win
- **category:** fleet composition · **confidence:** B · **status:** shipped
- **principle:** the goal of defence is maximum attacker damage per cost; "the best defense is the one that's never used".
- **inputs:** observed attacker, attack/cost ratios · **exceptions:** none.
- **sources:** ORG-003, FOR-007 (documented) · **ogamex:** supported · **code:** `QueueableUnitPlanner::bestDefense` (U3) · **priority:** P0

### FLE-004 — Composition, counters and the debris role by account stage
- **category:** fleet composition · **confidence:** B · **status:** researched
- **principle:** published play names stage bands (battleship/cruiser/recycler/cargo/probe), a debris-role (recycler/reaper/pathfinder harvest the field) and a moonshot role (light fighter); the module has cargo/defence/escort/probe roles only and no composition, counters or debris role.
- **inputs:** stage, tech level, target composition, debris expected · **exceptions:** none.
- **sources:** GF-001 (bands), WIK-003 (roles, cost-effectiveness) · **ogamex:** supported (host battle engine, `RecycleMission`) but unwired · **code:** none · **priority:** P1

### FLE-012 — Production and launch are two decisions
- **category:** fleet composition · **confidence:** C · **status:** researched
- **principle:** production is the stage-sized stock of hulls owned; launch is the minimum/optimal subset sent against one target, gated by simulation plus the profit gate — never the whole stock.
- **inputs:** owned hulls, target composition, target defence · **exceptions:** none.
- **sources:** TP-008, TP-015, RAID-001 (synthesis) · **ogamex:** supported (battle engine) but unwired · **code:** none · **priority:** P1

### FLE-013 — The launch subset is counter-selected per target
- **category:** fleet composition · **confidence:** B · **status:** researched
- **principle:** pick the minimum hulls that win against THIS target's mix — cruisers vs light-fighter swarms, destroyers vs battlecruisers, mixed hulls to dilute Deathstar rapid fire — with fodder only when the target can threaten heavy hulls; an undefended target collapses to kill ships plus cargo.
- **inputs:** target hull mix, defence, rapid-fire graph · **exceptions:** none.
- **sources:** TP-020, TP-018, TP-019, WIK-004 (documented) · **ogamex:** supported but unwired · **code:** none · **priority:** P1

### FLE-014 — The cruiser is the mid-game workhorse; the battleship ages out
- **category:** fleet composition · **confidence:** B · **status:** researched
- **principle:** the cruiser has the best damage-per-resource of the mid-game ships and forms the mid-game core; the battleship (standard at 100–1,000) ages out as battlecruisers and reapers (RF×7 vs battleship) become common.
- **inputs:** universe stage, opponent mix · **exceptions:** none.
- **sources:** TP-020, TP-018 (documented) · **ogamex:** supported but unwired · **code:** none · **priority:** P1

### FLE-015 — Simulate before dispatch; simulation is a probability average
- **category:** fleet composition · **confidence:** B · **status:** researched
- **principle:** run every attack through the combat simulator against the target's actual fleet/defence before dispatch, because a single simulation is a probability average and close fights vary.
- **inputs:** target composition, defender state · **exceptions:** none.
- **sources:** TP-015, TP-008 (documented) · **ogamex:** supported (`BattleEngine`) but unwired · **code:** none · **priority:** P2

## Fleetsave / survival

### FS-006 — Carry the resources with the fleet
- **category:** fleetsave · **confidence:** A · **status:** researched
- **principle:** a save is incomplete unless cargo is loaded too — in-flight resources cannot be raided, and a stripped planet is unprofitable to hit.
- **inputs:** planet stock, cargo capacity, loot cap · **exceptions:** none.
- **archetypes:** miner strong-positive, fleeter positive · **sources:** TP-009, GF-002, FOR-005 · **ogamex:** supported (cargo capacity quotes) but unwired · **code:** none · **priority:** P1

### FS-007 — Land after the expected login, with a buffer
- **category:** fleetsave · **confidence:** B (buffer magnitude contested) · **status:** researched
- **principle:** time the return after the expected online moment and pad with a buffer; the buffer is a persona band (10–20 min vs +30–60 min are contested).
- **inputs:** expected online time, duration, speed, buffer · **exceptions:** none.
- **archetypes:** all · **sources:** GF-002, FOR-005, TP-009 · **ogamex:** supported · **code:** none (only the skip rate today) · **priority:** P1

### FS-008 — Mask the dispatch moment with post-save activity
- **category:** fleetsave · **confidence:** B · **status:** researched
- **principle:** stay online briefly (or generate activity shortly) after launching so the departure timestamp is not readable off the activity star.
- **inputs:** departure timestamp, activity marker · **exceptions:** none.
- **archetypes:** fleeter strong-positive · **sources:** TP-009, FOR-005 · **ogamex:** supported (activity marker) but unwired · **code:** none · **priority:** P2

### FS-009 — Split the fleet across saves (shadow waves)
- **category:** fleetsave · **confidence:** B · **status:** researched
- **principle:** divide the fleet among missions/arrival waves so a phalanx-timed crash catches only part; slot cost is the ceiling.
- **inputs:** fleet size, slot count, recycler count · **exceptions:** small fleets (not worth splitting).
- **archetypes:** fleeter strong-positive · **sources:** GF-002, FOR-005, TP-009 · **ogamex:** supported (fleet slots) but unwired · **code:** none · **priority:** P2

### FS-010 — A recalled deployment is the phalanx-invisible save
- **category:** fleetsave · **confidence:** A · **status:** researched
- **principle:** a recalled deployment disappears from the phalanx entirely, making deploy-recall the safest pre-moon save; recall applies to a deploy between two own planets.
- **inputs:** deployment flight, recall moment, origin/destination type · **exceptions:** same-planet relocation is not recallable.
- **archetypes:** fleeter strong-positive, miner positive · **sources:** FOR-005, TP-009 · **ogamex:** supported (`cancelMission`→`startReturn`) but no module caller · **code:** none (`AiActionType::RecallFleet` unused) · **priority:** P1

### FS-001 — Save a valuable fleet before any offline gap
- **category:** fleetsave · **confidence:** B · **status:** researched
- **principle:** "if you go offline > 30 minutes with a valuable fleet, fleetsave it" — proactive exposure/fleet-value risk, not only a reaction to an inbound attack.
- **inputs:** fleet value, offline window, exposure · **exceptions:** none.
- **sources:** TP-007, TP-009 (documented) · **ogamex:** supported but the module is reactive only (`currentPlayerUnderAttack`) · **code:** none · **priority:** P1

### FS-002 — Vary landing time and route
- **category:** fleetsave · **confidence:** B · **status:** partial
- **principle:** "never save to the same landing time every day"; pattern-aware attackers deduce duration and intercept.
- **inputs:** landing time, route · **exceptions:** none.
- **sources:** TP-009, FOR-005 (documented) · **ogamex:** supported · **code:** only the skip rate varies today · **priority:** P1

### FS-003 — A save can fail, and the failure is named
- **category:** fleetsave · **confidence:** D · **status:** shipped (placeholder)
- **principle:** an authentic account occasionally fails ("overnight gamble", forgetting to relaunch); the rate is a placeholder (1/30) until telemetry replaces it.
- **inputs:** deterministic per-account draw · **exceptions:** none.
- **sources:** TP-009, GF-002 (named modes, no rate) · **ogamex:** supported · **code:** `SaveFailurePolicy` (V3) · **priority:** P0

### FS-004 — React inside a window before impact
- **category:** fleetsave · **confidence:** B · **status:** shipped (V2)
- **principle:** wake at `arrival − 120…180 s` to react to a hostile, never faster than the 10 s detector floor.
- **inputs:** inbound ETA · **exceptions:** none.
- **sources:** BOT-005 (verified) · **ogamex:** supported · **code:** `inboundThreat` reaction window + `reaction_wake_at` (V2) · **priority:** P2

### FS-005 — Enumerate destination × speed routes, not one default
- **category:** fleetsave · **confidence:** B · **status:** partial
- **principle:** score feasible mission×destination×speed combinations on exposure, fuel and schedule fit; the module picks the first other own planet at speed 1.0.
- **inputs:** owned destinations, speeds, fuel · **exceptions:** none.
- **sources:** BOT-005 (verified), TP-009 (documented) · **ogamex:** supported (deploy/recall semantics verified) · **code:** `QueueableFleetSavePlanner` first-fit · **priority:** P1

### FS-011 — Harvest and buddy-hold saves are phalanx-invisible
- **category:** fleetsave · **confidence:** B · **status:** researched
- **principle:** a moon-to-debris-field harvest save and a moon-to-buddy-moon hold both fly from a moon and are invisible to phalanx; without a moon the only safe save is a slowed deployment to one's own colony, recalled before arrival.
- **inputs:** moon presence, debris field, buddy moon, speed · **exceptions:** none.
- **sources:** PLW-001 (documented) · **ogamex:** supported (harvest/hold missions) but unwired · **code:** none · **priority:** P2

## Espionage / intelligence

### INT-005 — The activity stamp is a planet-context action
- **category:** espionage · **confidence:** A · **status:** researched
- **principle:** the 15-min marker is set by any planet-context request, not an open tab; it names which planet was touched and when.
- **inputs:** planet-context actions, `time_last_update` · **exceptions:** idle tab sets nothing.
- **archetypes:** all; fleeter-critical · **sources:** TP-006 · **ogamex:** supported (`GalaxyController::getPlanetActivityStatus`) but unwired · **code:** none · **priority:** P1

### INT-006 — Moon-only activity signals phalanx/jump-gate use
- **category:** espionage · **confidence:** B · **status:** researched
- **principle:** activity on a moon with none on the planet signals moon facilities in use — a warning while your fleet is in transit.
- **inputs:** moon vs planet `time_last_update` · **exceptions:** none.
- **archetypes:** fleeter strong · **sources:** TP-006 · **ogamex:** supported but unwired · **code:** none · **priority:** P2

### INT-007 — Control your own signature; never hover after dispatch
- **category:** espionage · **confidence:** B · **status:** researched
- **principle:** refreshing the launch planet after sending creates an activity chain advertising "something is in flight"; dispatch and disappear.
- **inputs:** own activity pattern · **exceptions:** none.
- **archetypes:** all; fleeter-critical · **sources:** TP-006 · **ogamex:** supported but unwired · **code:** none · **priority:** P2

### INT-008 — Espionage-tech level gates report completeness
- **category:** espionage · **confidence:** A · **status:** researched
- **principle:** what a report reveals is thresholded by espionage tech vs target level (host `canRevealData`), so an incomplete report means "send more probes", not "probe failed".
- **inputs:** espionage tech, target levels, missing fields · **exceptions:** none.
- **archetypes:** fleeter strong · **sources:** TP-008, host `EspionageMission::canRevealData` · **ogamex:** supported but unwired (module sends one probe) · **code:** `QueueableSpyPlanner` · **priority:** P2

### INT-009 — Repeated probing of an active target alerts it
- **category:** espionage · **confidence:** B · **status:** researched
- **principle:** probing an active target repeatedly is itself a tell; scout once, act fast, or move on.
- **inputs:** probe count per target, target activity · **exceptions:** inactive farms.
- **archetypes:** fleeter · **sources:** TP-008 · **ogamex:** supported · **code:** none · **priority:** P2

### INT-010 — The activity timer is per-planet and absolute
- **category:** espionage · **confidence:** A · **status:** researched
- **principle:** each planet/moon carries its own 15-min timestamp; the star vanishes exactly 15 min after that body's last update.
- **inputs:** per-planet `time_last_update` · **exceptions:** none.
- **archetypes:** all · **sources:** TP-006 · **ogamex:** supported but unwired · **code:** none · **priority:** P2

### INT-001 — Scout is bounded: each target once per intel window
- **category:** espionage · **confidence:** B · **status:** shipped
- **principle:** each neighbour is probed once per window, then the account stops probing and acts on the intel; re-probing the same farm forever is the tell that was removed.
- **inputs:** fresh-intel coordinates, in-flight probes · **exceptions:** re-probe escalation when intel is insufficient (INT-002).
- **sources:** TP-006, BOT-003 target lifecycle · **ogamex:** supported · **code:** `QueueableSpyPlanner::freshIntelCoordinates` (N1/N2) · **priority:** P0

### INT-002 — Escalate probe count when a report is incomplete
- **category:** espionage · **confidence:** B · **status:** partial
- **principle:** "send 5–10 probes"; TBot re-probes at ×3 then ×9 when the report does not reveal; the module sends exactly one probe.
- **inputs:** report completeness, probe availability · **exceptions:** none.
- **sources:** TP-008 (documented), BOT-003 (verified) · **ogamex:** supported · **code:** single probe today · **priority:** P2

### INT-003 — Prioritize targets, do not take the first legal one
- **category:** espionage · **confidence:** B · **status:** researched
- **principle:** target selection should score distance, likely yield and novelty, not walk planets in `id` order.
- **inputs:** distance, galaxy position, prior knowledge · **exceptions:** none.
- **sources:** TP-006, BOT-005 · **ogamex:** supported but first-fit · **code:** `QueueableSpyPlanner::target` first-fit · **priority:** P1

### INT-004 — The activity star is intelligence, not decoration
- **category:** espionage · **confidence:** A · **status:** researched
- **principle:** the 15-minute activity marker names *which* planet was touched and when; it feeds both raid risk (RAID-004) and target choice — and it must never be triggered deliberately.
- **inputs:** planet `time_last_update`, activity status · **exceptions:** none.
- **sources:** TP-006 (documented), host-verified · **ogamex:** supported (host galaxy activity + `Planet.time_last_update`) but unwired · **code:** none · **priority:** P1

### INT-011 — Scan from home, not from the colony you are attacking
- **category:** espionage · **confidence:** B · **status:** researched
- **principle:** never launch espionage scans from a battle colony — scan from the home planet so the target cannot read your presence from the activity stamp.
- **inputs:** origin body, target activity · **exceptions:** none.
- **sources:** PLW-003 (documented) · **ogamex:** supported (activity marker) but unwired · **code:** none · **priority:** P2

### INT-012 — Scan by day, never re-scan the same fleet by night
- **category:** espionage · **confidence:** B · **status:** researched
- **principle:** seek fleets with scans during the day and do not re-scan the same fleet at night, so a probe reads as a random daytime scan rather than a hunt on a specific fleet.
- **inputs:** time of day, prior scans · **exceptions:** none.
- **sources:** PLW-003 (documented) · **ogamex:** supported but unwired · **code:** none · **priority:** P2

## Fleetcrash / phalanx / moon

### CRASH-005 — Moon destruction flips the geography
- **category:** fleetcrash · **confidence:** B · **status:** researched
- **principle:** destroying a moon redirects its returning fleets to the planet (phalanx-visible) and auto-recalls foreign fleets en route; no debris field results.
- **inputs:** moon presence, deathstar availability, mission type 9 · **exceptions:** none.
- **archetypes:** fleeter strong-positive (both sides) · **sources:** ORG-011 (Wayback), FOR-005 · **ogamex:** supported (`MoonDestructionMission` type 9; redirect not re-verified) · **code:** none · **priority:** P2

### CRASH-006 — Moons are phalanx-invisible
- **category:** fleetcrash · **confidence:** A · **status:** researched
- **principle:** the phalanx cannot scan a moon, so a moon-launched save is invisible — the load-bearing fact behind moon-to-moon deploy being the safest save.
- **inputs:** departure type, phalanx range · **exceptions:** none.
- **archetypes:** fleeter strong-positive, miner positive · **sources:** ORG-011 (Wayback), FOR-005, TP-009, GF-002 · **ogamex:** supported (`PhalanxService`) but unwired · **code:** none · **priority:** P1

### CRASH-007 — Phalanx is a two-sided tool
- **category:** fleetcrash · **confidence:** C (framing synthesis) · **status:** researched
- **principle:** the same phalanx that lets you time a crash on an enemy return is the reason a planet-launched save is unsafe — awareness shapes both offense and your own saves.
- **inputs:** phalanx level/range, departure type, scan cost · **exceptions:** none.
- **archetypes:** fleeter strong-positive · **sources:** ORG-011 (Wayback), GF-002, TP-009, FOR-005 · **ogamex:** supported (`PhalanxService::calculatePhalanxRange`, `getScanCost` 5000) but unwired · **code:** none · **priority:** P1

### CRASH-008 — Blind lanx via debris-field disappearance
- **category:** fleetcrash · **confidence:** B · **status:** researched
- **principle:** a harvest save leaks its arrival the instant its debris field vanishes; an observer back-calculates the return and crashes it without seeing the fleet.
- **inputs:** DF visibility (>300 units), recycler arrival, victim drive tech · **exceptions:** invisible DF (<300 units), shadow waves.
- **archetypes:** fleeter strong-positive (both) · **sources:** FOR-005, TP-009 · **ogamex:** supported (DF mechanics) but unwired · **code:** none · **priority:** P2

### CRASH-001 — Debris is never guaranteed income
- **category:** fleetcrash · **confidence:** B · **status:** partial
- **principle:** a crash's debris is a second recycler mission with its own capacity and timing; it is not added to raid profit.
- **inputs:** debris amount, recycler count, timing · **exceptions:** none.
- **sources:** veteran-play §7 (documented), WIK-003 (recycler 20k; reaper/pathfinder also debris-capable) · **ogamex:** supported (`DebrisFieldService`, `RecycleMission` type 8, `calculateRequiredRecyclers`) but **unwired** · **code:** none · **priority:** P1

### CRASH-002 — Phalanx covers a range; scan only what it covers
- **category:** fleetcrash · **confidence:** C · **status:** researched
- **principle:** phalanx scan cost/range is a host answer; it reveals return fleets within coverage — a fleeter scans before a crash, a victim knows it can be scanned.
- **inputs:** phalanx level, range, deuterium cost · **exceptions:** moon-less planets.
- **sources:** ORG-011 (Wayback recovered), FOR-006 · **ogamex:** supported (`PhalanxService::calculatePhalanxRange` = level²−1, Discoverer +20%; `getScanCost` = 5000) but **unwired** · **code:** none · **priority:** P1

### CRASH-003 — Deploy-recall interception is timing, not force
- **category:** fleetcrash · **confidence:** C · **status:** researched
- **principle:** recall a deployment at ~half flight to time a return; the module owns the recall seam but no caller.
- **inputs:** flight time, recall window · **exceptions:** deploy between two own planets only; a same-planet relocation is not recallable.
- **sources:** BOT-007 (verified 98–101% of half flight) · **ogamex:** supported (`cancelMission` → `startReturn`; `AiActionType::RecallFleet` enum unused) · **code:** none · **priority:** P1

### CRASH-004 — Moon and jump gate change geography and timing
- **category:** fleetcrash · **confidence:** C · **status:** researched
- **principle:** a moon enables phalanx, safer saves and jump-gate movement; its value is coverage, not decoration.
- **inputs:** moon presence, jump gate cooldown · **exceptions:** none.
- **sources:** ORG-011 (Wayback recovered) · **ogamex:** supported (`JumpGateService::calculateCooldown`, moon as `PlanetType::Moon`) but **unwired** · **code:** none · **priority:** P2

### CRASH-009 — Phalanx reveals the exact return second
- **category:** fleetcrash · **confidence:** A · **status:** researched
- **principle:** a phalanx scan shows a returning fleet's exact return timestamp, so the crash is timed to arrive seconds after the return — too fast to re-save.
- **inputs:** phalanx level/range, return timestamp · **exceptions:** moon-launched returns (invisible).
- **sources:** WIK-006, WIK-009, GF-006 (documented) · **ogamex:** supported (`PhalanxService`) but unwired · **code:** none · **priority:** P1

### CRASH-010 — The speed slider is the timing tool
- **category:** fleetcrash · **confidence:** A · **status:** researched
- **principle:** travel time scales inversely with the 10–100% speed slider, so the attacker back-calculates the speed percentage that lands on the target's return second; the slowest hull sets the fleet speed.
- **inputs:** flight time, target return, slowest hull speed · **exceptions:** none.
- **sources:** TP-014, WIK-006 (documented) · **ogamex:** supported (speed quote) but unwired · **code:** none · **priority:** P1

### CRASH-011 — Timing precision tiers
- **category:** fleetcrash · **confidence:** B · **status:** researched
- **principle:** landing 0–2 s after the return is a clean timing attack; 3–5 s gives an active defender a narrow re-launch window; more than 5 s risks a miss.
- **inputs:** arrival delta, defender watchfulness · **exceptions:** none.
- **sources:** TP-014 (documented) · **ogamex:** supported but unwired · **code:** none · **priority:** P2

### CRASH-012 — Blind phalanx estimates the return from the routine
- **category:** fleetcrash · **confidence:** B · **status:** researched
- **principle:** a crash timed without a phalanx learns the victim's online/offline pattern and fleetsave routine and estimates the return from it — a fresh activity dot means interaction in the last ~5 minutes, no activity in the ~20 before return means likely offline.
- **inputs:** activity history, fleetsave routine, return estimate · **exceptions:** none.
- **sources:** WIK-007, TP-014 (documented) · **ogamex:** supported (activity marker) but unwired · **code:** none · **priority:** P1

### CRASH-013 — A recall's disappearance from the phalanx is itself a tell
- **category:** fleetcrash · **confidence:** B · **status:** researched
- **principle:** a recalled deployment drops off the phalanx, but its disappearance is readable — skilled fleeters time that disappearance to hit the new return.
- **inputs:** phalanx before/after, recall timing · **exceptions:** none.
- **sources:** WIK-007 (documented) · **ogamex:** supported (`cancelMission`) but unwired · **code:** none · **priority:** P2

### CRASH-014 — The crash EV includes debris, read from the host
- **category:** fleetcrash · **confidence:** A · **status:** researched
- **principle:** a crash's net profit is `debris collected + cargo looted − own ship losses − deuterium`, with own losses costed at resource value minus the debris refund of one's own wreckage; the debris percentage is a host/universe setting (vanilla 30%, some 70%, private servers 43–80%), never a module constant.
- **inputs:** debris %, loot, losses, fuel · **exceptions:** debris harvested separately (CRASH-001).
- **sources:** GF-006, TP-012, WIK-008, TP-013 (documented) · **ogamex:** supported (debris %) but unwired · **code:** none · **priority:** P1

## Ninja / baiting

### NIN-001 — The ninja turns a raid into an attacker kill
- **category:** ninja · **confidence:** A · **status:** researched
- **principle:** a ninja keeps the real defensive fleet away from the bait planet (hidden on a moon or a timed-return mission) and lands it just before the attacker's fleet arrives, so it fights as defender and kills the attacker.
- **inputs:** bait planet, hidden fleet, attacker ETA · **exceptions:** none.
- **sources:** WIK-005, TP-011 (documented) · **ogamex:** supported (fleet/moon/defence) but unwired · **code:** none · **priority:** P2

### NIN-002 — The ninja fleet lands on the combat second
- **category:** ninja · **confidence:** B · **status:** researched
- **principle:** the ninja fleet must be present at the combat second — same-second or a ~1 s server-tick buffer; landing seconds early exposes it to a recall, landing after misses the battle.
- **inputs:** attacker ETA, own fleet timing · **exceptions:** none.
- **sources:** TP-011, WIK-005 (documented) · **ogamex:** supported but unwired · **code:** none · **priority:** P2

### NIN-003 — Bait reads as a soft farm, not a trap
- **category:** ninja · **confidence:** B · **status:** researched
- **principle:** bait leaves a resource pile large enough to make a raider's profit math positive while keeping stationary defence light, so the target reads as a soft farm; a fortress or an obvious trap deters instead of lures.
- **inputs:** resource pile, defence levels · **exceptions:** none.
- **sources:** TP-011 (documented) · **ogamex:** supported but unwired · **code:** none · **priority:** P2

### NIN-004 — The trap fleet is absent from the spy report
- **category:** ninja · **confidence:** A · **status:** researched
- **principle:** the trap fleet is staged on a nearby moon (moon ninja) or away on a mission timed to return before impact; the moon-lure variant parks a Deathstar plus a wall of solar satellites to bait a big attack and force a moon-forming debris field.
- **inputs:** moon presence, return timing, bait composition · **exceptions:** none.
- **sources:** TP-011, WIK-005 (documented) · **ogamex:** supported (moon, debris) but unwired · **code:** none · **priority:** P2

### NIN-005 — An attacker defends against ninjas by checking for staging
- **category:** ninja · **confidence:** B · **status:** researched
- **principle:** phalanx the target's nearby planets for staged fleets, probe when no moon is available, abort if defensive fleet movement appears, and send a last-second espionage probe to confirm the defender's fleet is still on the planet before impact.
- **inputs:** phalanx coverage, probe timing, movement signals · **exceptions:** none.
- **sources:** WIK-005, TP-014 (documented) · **ogamex:** supported but unwired · **code:** none · **priority:** P2

## ACS / coordination

Alliance-gated (host `allianceCombatSystemOn`). Execution deferred to Package 6; recorded as knowledge now, not discarded.

### ACS-001 — ACS attack merges fleets into one battle
- **category:** ACS · **confidence:** B · **status:** researched (alliance-gated)
- **principle:** multiple players send fleets to one target at one second and all fight as a single side.
- **inputs:** target coords, participant fleets, shared arrival · **sources:** GF-003 · **ogamex:** supported (`FleetUnionService`, type 2) but unwired · **priority:** P3

### ACS-002 — ACS is formed by invitation and join
- **category:** ACS · **confidence:** A · **status:** researched (alliance-gated)
- **principle:** one player creates the fleet union and invites others, who join with their own fleets before the shared impact.
- **inputs:** invitation, join acceptance, per-member fleet · **sources:** GF-003, host `FleetUnionService` · **ogamex:** supported but unwired · **priority:** P3

### ACS-003 — Synchronize all fleets to the same impact second
- **category:** ACS · **confidence:** B · **status:** researched (alliance-gated)
- **principle:** every participating fleet arrives at the exact same second.
- **inputs:** per-fleet duration/speed, shared ETA · **sources:** GF-003 · **ogamex:** supported but unwired · **priority:** P3

### ACS-004 — The slowest attacker anchors the arrival time
- **category:** ACS · **confidence:** B · **status:** researched (alliance-gated)
- **principle:** the slowest fleet fixes the impact time; faster fleets slow to match it.
- **inputs:** slowest ETA, each fleet speed · **sources:** GF-003 · **ogamex:** supported but unwired · **priority:** P3

### ACS-005 — Espionage precedes the coordinated hit
- **category:** ACS · **confidence:** B · **status:** researched (alliance-gated)
- **principle:** probe the target's defence and fleet before committing the combined attack.
- **inputs:** espionage report · **sources:** GF-003 · **ogamex:** supported but unwired · **priority:** P3

### ACS-006 — Explicit assignment of who sends what, when, where
- **category:** ACS · **confidence:** B · **status:** researched (alliance-gated)
- **principle:** coordination requires clear communication of who sends which fleet, when, where.
- **inputs:** member roles, fleet assignment, timing plan · **sources:** GF-003 · **ogamex:** supported but unwired · **priority:** P3

### ACS-007 — ACS hits targets no single player could
- **category:** ACS · **confidence:** B · **status:** researched (alliance-gated)
- **principle:** the combined attack enables hits on well-defended planets and strong fleeters that no individual fleet could manage.
- **inputs:** target strength vs combined alliance strength · **sources:** GF-003 · **ogamex:** supported but unwired · **priority:** P3

### ACS-008 — ACS defend stations allied fleets via the Alliance Depot
- **category:** ACS · **confidence:** B (Depot building unconfirmed in host survey) · **status:** researched (alliance-gated)
- **principle:** an Alliance Depot lets allied fleets hold in orbit to defend; capacity scales with depot level.
- **inputs:** depot level, allied fleet count · **sources:** GF-003, host `AcsDefendMission` type 5 · **ogamex:** partial (type 5 supported; Depot unverified) · **priority:** P3

### ACS-009 — ACS is ordinary alliance play, not war-only
- **category:** ACS · **confidence:** C (synthesis) · **status:** researched (alliance-gated)
- **principle:** ACS is a standing alliance capability (protection, crashing, domination, toplists), not gated to formal war.
- **inputs:** alliance membership · **sources:** veteran-play §8, pve-empire, pve-precedents · **ogamex:** supported but unwired · **priority:** P3

### ACS-010 — Second-precision ACS is the one real-time case
- **category:** ACS · **confidence:** C (framing) / B (timing fact) · **status:** researched (alliance-gated)
- **principle:** among documented social latencies, only ACS demands second-precision timing.
- **inputs:** arrival alignment vs reply cadence · **sources:** GF-003, player-communication · **ogamex:** supported but unwired · **priority:** P3

### ACS-011 — ACS splits fall under the 72-hour rule
- **category:** ACS · **confidence:** B · **status:** researched (alliance-gated)
- **principle:** trades, recycling help and ACS splits must complete within 72 hours.
- **inputs:** split obligations, completion timestamp · **sources:** GF-005 §5 · **ogamex:** supported · **priority:** P3

### ACS-012 — Host supports ACS attack/defend but the module is unwired
- **category:** ACS · **confidence:** A · **status:** researched (alliance-gated)
- **principle:** host implements ACS attack (type 2) and defend (type 5); no module planner/action references them.
- **inputs:** `allianceCombatSystemOn`, types 2/5 · **sources:** host-capability-map, GAP-REGISTER W6-5 · **ogamex:** supported but unwired · **priority:** P3

### ACS-013 — A joining fleet may not slow the group past 30%
- **category:** ACS · **confidence:** B · **status:** researched (alliance-gated)
- **principle:** the ACS group flies at its slowest fleet's speed, and a later joining fleet may not slow the group by more than 30% of the current flight time; Death Stars cannot join an ACS moon-destruction mission.
- **inputs:** join ETA, current group flight time · **exceptions:** none.
- **sources:** PLW-004 (documented) · **ogamex:** supported (type 2) but unwired · **priority:** P3

### ACS-014 — ACS debris splits by agreement
- **category:** ACS · **confidence:** B · **status:** researched (alliance-gated)
- **principle:** the loot/debris split is by agreement; the simplest convention divides debris into a loss-equalizing share (by lost units) and a profit share proportional to each player's fleet contribution.
- **inputs:** losses, contribution · **exceptions:** none.
- **sources:** PLW-004 (documented) · **ogamex:** supported but unwired · **priority:** P3

## Routine / authenticity

### AUTH-001 — The host detector thresholds are hard constraints
- **category:** authenticity · **confidence:** A · **status:** shipped
- **principle:** <18 distinct active hours per 7 days, never react <10 s, a real nightly dark period — these bound every schedule, whatever the persona.
- **inputs:** departure hours, reaction latencies · **exceptions:** none.
- **sources:** host-verified, veteran-play §5 · **ogamex:** supported · **code:** `SessionPlanner` (H1) · **priority:** P0

### AUTH-002 — Sessions and gaps are heavy-tailed, never uniform
- **category:** authenticity · **confidence:** A · **status:** shipped
- **principle:** draw session length and gap from a Weibull with shape ≈ 0.7–0.9; a uniform window is exactly what a periodicity detector looks for.
- **inputs:** mean wait, shape · **exceptions:** none.
- **sources:** ACA-004, ACA-005 (measured), BOT corpus · **ogamex:** supported · **code:** `SessionPlanner` Weibull draws (H2) · **priority:** P0

### AUTH-003 — Plan real absences
- **category:** authenticity · **confidence:** B · **status:** shipped
- **principle:** single days, multi-day gaps and a yearly week-long absence, decided once per waking day.
- **inputs:** absence draw · **exceptions:** never past ~28 days.
- **sources:** veteran-play §9 (measured/estimate) · **ogamex:** supported · **code:** H3 · **priority:** P0

### AUTH-004 — Wake at the next material event, not on a poll
- **category:** authenticity · **confidence:** A · **status:** shipped (SP3)
- **principle:** sleep until the earliest of resource ETA, queue finish, fleet arrival/return, slot free, storage threshold — the one idea every surveyed bot shares.
- **inputs:** ETAs · **exceptions:** none.
- **sources:** BOT-003/BOT-005/BOT-006 (verified) · **ogamex:** supported · **code:** `SessionDecisionService::nextMaterialEventWake` + `SessionPlanner::isAwake` (IMPL-042) · **priority:** P1

## Social / diplomacy

### SOC-001 — Speak rarely and in context; answer reliably
- **category:** social · **confidence:** B · **status:** deferred
- **principle:** initiating contact needs a trigger and a recipient; answering stays shipped.
- **inputs:** contact trigger · **exceptions:** none.
- **sources:** personas/communication research · **ogamex:** partial · **code:** SOC1 deferred to Package 6 · **priority:** P3

### SOC-002 — Alliance life beyond observation
- **category:** social · **confidence:** C · **status:** deferred
- **principle:** apply, then behave like a member; membership is observed today but never initiated.
- **inputs:** alliance state · **exceptions:** none.
- **sources:** FOR-009 (documented) · **ogamex:** partial · **code:** SOC2 deferred to Package 6 · **priority:** P3

## Expeditions

### EXP-001 — Expeditions are not a fleetsave
- **category:** expeditions · **confidence:** B · **status:** researched
- **principle:** expeditions must never be used as a fleetsave because there is a small chance the whole fleet vanishes.
- **inputs:** fleet composition, mission choice · **exceptions:** none.
- **sources:** PLW-005 (documented) · **ogamex:** not re-verified (expedition mission exists, ORG-012) · **code:** none · **priority:** P2

### EXP-002 — Expedition outcomes are bounded and slot-16 only
- **category:** expeditions · **confidence:** B · **status:** researched
- **principle:** expeditions target only slot 16 of a system; outcomes include found ships (never Death Stars), antimatter, resources capped at cargo capacity, traders, empty return, pirate/alien attack, a rare total fleet loss, and a navigation error that shifts the return time.
- **inputs:** slot 16, fleet, cargo · **exceptions:** none.
- **sources:** PLW-005 (documented) · **ogamex:** not re-verified · **code:** none · **priority:** P2
