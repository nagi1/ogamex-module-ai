# What automation tools already solved

Research note, 14 September 2026, rewritten after a second, deeper pass. Public OGame automation
projects were read for their **decision algorithms** — planning, ordering, accounting and scheduling — as
engineering evidence for the in-product AI and the offline simulator. This pass read the real source of
sixteen projects rather than their READMEs, and it corrects several claims the first version of this
note made from summaries.

No client automation, scraping, session or evasion technique was collected or is to be used: automating
an official OGame account is forbidden by the game's rules and terms, which is also why none of this may
leave the module's own universe. [OGame rules §6](https://en.ogame.gameforge.com/ajax/main/rules)

Where a constant is quoted below it was **read in the project's source**, at the path given. Where a
claim rests on a README or a forum post it says so. Where a file could not be fetched it says so too.

## Why this is worth reading at all

These projects are unfashionable but not naive. Several solve exactly the problems this module has —
what to build next, when to fix energy, when to save, which farm pays — in a few hundred lines of
arithmetic and with no model of any kind. The recurring shapes are worth adopting; the way almost all of
them *store* game knowledge is exactly what [gate 1](../specs/cognition-gates.md) forbids, and seeing
eleven independent authors make the same mistake is the strongest argument for the gate there is.

The algorithms themselves are specified for our module in
[the gameplay algorithms](../specs/gameplay-algorithms.md); this note is the evidence base behind it.

## The projects surveyed

| Project | Language | Driver | What is worth reading | Read |
| --- | --- | --- | --- | --- |
| [`trilogi77/OgameBot`](https://github.com/trilogi77/OgameBot) | Python | cycle + night watch | The most complete decision engine in the corpus: marginal payback in metal-equivalent hours against an **adaptive capped threshold**, a per-resource savings reserve, and the only one-build guard that defends against a stale read | source |
| [`halfguru/ogamebot`](https://github.com/halfguru/ogamebot) | Go | poll loop + SQLite | Weighted-ROI ordering, safety-scored fleet-save routes, storage at 0.8 of capacity, jitter on every interval, and an `internal/ogamex` package that contains no queue or occupancy logic | source |
| [`ogame-tbot/TBot`](https://github.com/ogame-tbot/TBot) | C# | worker loops | `CalcDaysOfInvestmentReturn`, the research hurdle, the energy ladder, the **target state machine**, wake-at-next-event, and a save that can genuinely fail | source |
| [`jaesivsm/pyogame`](https://github.com/jaesivsm/pyogame) | Python | tick loop | **Requirement closure** as a recursive generator, the predictive energy gate as an exact comparison, mine offsets, tank triggers | source |
| [`kweimann/cruiser`](https://github.com/kweimann/cruiser) | Python | sleep loop | Per-wakeup state cache, hostile-event detection with a **reaction window**, destination × speed route enumeration, a bounded retry ladder | source |
| [`PHPOgameBot`](https://github.com/racinmat/PHPOgameBot) | PHP | cron + 60 s poll | **Dependency-typed queue**, earliest-availability as a max over blockers, storage auto-insert, resource projection from stale intel, a closed-form probe-count solver | source |
| [`PiecePaperCode/barakis`](https://github.com/PiecePaperCode/barakis) | Python | single loop | The smallest readable `(action, condition)` priority list in the corpus — the canonical negative example for gate 1 | source |
| [`ogame-ninja/scripts`](https://github.com/ogame-ninja/scripts) | Go | cron + sleeps | Literal step arrays, a per-weekday sleep schedule with 8–10 h nights, deploy-and-recall at 98–101 % of half the flight | source |
| [`r4fek/ogame-bot`](https://github.com/r4fek/ogame-bot) | Python | interval loop | Offset ladder (`−2 / −5`) with a per-mine predictive energy test and cross-planet level-spread selection | source |
| [`ogame-infinity/web-extension`](https://github.com/ogame-infinity/web-extension) | JS | userscript | `need = max(target − onHand − inFlight, 0)`, with each mission classified as debiting the origin, crediting the destination, or neither | source |
| [`jstar88/Ogame-algorithms`](https://github.com/jstar88/Ogame-algorithms) | PHP | library | The plunder split and the ACS capacity weighting as code, plus cost, cumulative-cost and decay helpers. **The only project with no hardcoded object universe** | source |
| [`jstar88/opbe`](https://github.com/jstar88/opbe) | PHP | library | An **expected-value** battle engine in O(1) — a modelling choice, not a variance measurement | source |
| [`peterradzisz/ogame-fleet-optimizer`](https://github.com/peterradzisz/ogame-fleet-optimizer) | Python + Rust | offline tool | Common random numbers across a generation, a screening→confirmation sample ladder, a mid-evaluation wall-clock deadline, a published percentile method | source |
| [`klaasvp/trashsim-public`](https://github.com/klaasvp/trashsim-public) | PHP + JS | offline tool | `N` full simulations, fresh state per run, per-run records, aggregation upstream — and a documented mean-profit failure | source |
| [`maximalcode/ogame-combat-sim`](https://github.com/maximalcode/ogame-combat-sim) | Rust | offline tool | Distribution reporting (win counts, per-run detail) and the statement that "a single battle tells you almost nothing" | README |
| [`rbardtke/OGameX-Combat-Simulator`](https://github.com/rbardtke/OGameX-Combat-Simulator) | JS + Rust/WASM | offline tool | A **second implementation of OGameX's own combat formulas** in another language — the duplication the module contract forbids | README |

## The recurring patterns

Each pattern is what more than one project independently converged on. The names P1–P8 come from the
first version of this note and are kept; P9–P13 are new.

### P1 — Marginal payback ordering

`trilogi77/ogbot/economy.py`, `halfguru/internal/builder/roi.go`, TBot's `CalculationService.GetNextMineToBuild`,
`pyogame`'s mine ladder — **all verified in source**.

```text
for each candidate upgrade:
    cost   = host price of the next level, converted to one currency
    gain   = host production at level+1 − host production at level
    score  = gain / cost                       # higher is better
take the best; re-evaluate after every completion, because the ranking moves
```

Three independent weightings, all reducing to one currency: `M + 1.5 C + 2.0 D` (halfguru), a
`(2.5, 1.5, 1.0)` trade ratio (trilogi77), and `1 / 2.5` and `1 / 1.5` production divisors (TBot). The
players' own words agree: "prioritize mines with lowest amortization first" (**documented**,
[miner guide](https://board.en.ogame.gameforge.com/index.php?thread/821043-updated-the-ultimate-miner-guide-v-2/)).

*trilogi77* adds the piece worth keeping:

```text
payback_hours = metalEquivalent(cost) / extraProductionPerHour
threshold     = min(168 h, 24 h × (1 + average mine level / 20))     # adaptive, capped at a week
build only if payback_hours <= threshold
```

**Correction to the first version of this note.** It claimed the module "should compare return per hour
of queue". No project in the corpus does that: five of them read the queue only as a boolean "is
something building" guard, and halfguru computes the construction time and never feeds it back into its
ordering key. The claim was ours, not theirs, and
[the gameplay algorithms](../specs/gameplay-algorithms.md#e2-queue-occupancy-honest-about-what-is-not-proven)
now records it as an unproven tie-break to be measured before it is adopted.

### P2 — Priority list with affordability and cap gates

`barakis/src/buildings.py`, `r4fek`, `pyogame`, TBot's research caps — **all verified**.

```text
for entry in authored_priority_list:
    if not entry.condition(state): continue
    if level(entry.item) >= entry.cap: continue
    if not affordable(entry.item): continue
    return entry.item
```

Barakis is the plainest: one ordered list of `(build, condition)`, first satisfied condition wins per
planet per pass, with ceilings of 30/25/15 for the three mines, 25 for the research-network guard and
defence batches of 100/100/100/10/10/5/5 capped at 1000/1000/400/100/100/40/60. It also contains a
mis-guarded entry (the computer-technology condition tests the *espionage* level), which is the kind of
defect a table invites. TBot carries 14 per-technology caps (energy 20, laser 12, ion 5, astrophysics 23,
drives 19/17/15, weapons 25…) and, in the same file, a hand-written prerequisite triple for plasma.
**The pattern is fine for a persona's taste; it is not fine as the reason a capability is reachable, and
the caps restate what a host requirement graph already knows.**

### P3 — Requirement closure

`pyogame/pyogame/planet.py::requirements_for`, `trilogi77/ogbot/prereqs.py`, TBot's
`GetLFBuildingRequirements` and `halfguru`'s two prerequisite tables — **all verified**.

```text
expand(want):
    for requirement in host.requirements(want):
        if level(requirement) < requirement.level: yield from expand(requirement)
    if want.level > current_level(want) + 1: yield from expand(want.at(level − 1))
    yield want
```

The pattern is right and the storage is wrong: every one of these hardcodes the requirement table, which
is why `pyogame` ships a hand-typed tank-capacity dictionary with the comment *"fugly, couldn't find the
true formula for that one"*, and why a host that adds an object breaks the tool. `trilogi77` adds the
one thing the generator needs and `pyogame` lacks: a `visited` set for cycle protection. The module
computes the same closure from the host's own graph, which is what `FacilityChain` does.

### P4 — Threshold rules, including the energy gate

`pyogame` (predictive), `halfguru` (reactive `energy < 0`), `barakis` (reactive), `trilogi77` (a full
simulated balance), `r4fek` (per-mine) — **all verified**.

```text
pyogame:    if next_level_energy_cost * 0.95 > planet.energy:   build the solar plant
r4fek:      sufficient_energy = resources.energy − upgrade_energy_cost(next) > 0
halfguru:   if planet.energy < 0:                               build capacity
trilogi77:  balance = real + (simulated production − real) − (simulated consumption − real)
```

The `0.95` factor appears once, in `pyogame`, and it is a *margin*: it plans one level ahead. `r4fek`
reaches the same behaviour with a plain inequality, which is the smaller form and the one the module
uses. Halfguru's reactive form is what the fandom opening guide describes and what the module
deliberately does not do (see the [contested energy
doctrine](veteran-play.md#2-energy--the-one-place-the-sources-genuinely-disagree)).

### P5 — One action, then sleep until the slot frees

`halfguru` (`requireFreeBuildSlot`), TBot (`countdown + jitter`), `ogame-ninja`
(`SleepSec(countdown + 10)`), `pyogame` (one construction per idle planet), `barakis` (one build per
planet per pass) — **all verified**.

```text
if planet.has_active_construction: return
issue(build)
schedule(next_attempt, now + production_time(build) + jitter)
```

The strongest version is *trilogi77*'s: the live "is something building" flag is backed by a cached
finish epoch, so a fail-open overview read cannot cause a double enqueue. That is the guard the module's
planning path needs, expressed once in
[S4](../specs/gameplay-algorithms.md#sp4--one-mutating-action-at-a-time-per-contended-resource).

### P6 — Raid profit accounting

`halfguru/internal/farmer/farmer.go`, `ogame-ninja`'s `OptimalFarmer.go`, TBot's `AutoFarmWorker`,
`PHPOgameBot`'s `FarmsAttacker` — **all verified**, and they do not all do it well.

```text
TBot:        loot floor 1,000,000; report kept 180 min; 10 probes, ×3 then ×9 when the report is
             incomplete; cargos = payload / capacity × 1.10; max 18 concurrent attacks leaving one slot free;
             scan interval 30–60 min; minimum player rank 500; minimum loot:fuel ratio 0.5
halfguru:    minimum profit 10,000; max 5 probes per target; max 3 attacks per cycle; flat 0.5 plunder
ninja:       spy 3 probes per target; cargo = fast_cargo(resources/2) + 10 %; min rank 3,000;
             attack only if last_active == 0
PHPOgameBot: rank inactive, defenceless planets by projected stockpile; cargos = ceil(total / 2 / 25000)
```

The published test they are all approximating is looser than the guides state: profit = loot − deuterium
− expected losses, loot at least **3×** the deuterium, losses at most **20–30 %** of the loot
(**documented**, [Gameforge raider
guide](https://gameforge.com/en-GB/games/ogame-raider-guide.html)). Not one of them implements it:
`PHPOgameBot` has **no fuel or travel-time arithmetic anywhere** and counts cargo hulls against a
one-way trip; halfguru applies a flat plunder ratio rather than the game's resource-by-resource split;
none models defender uncertainty. This is where the corpus is weakest and where the module's estimator
([T2](../specs/gameplay-algorithms.md#t2-the-estimator)) earns its keep.

### P7 — Fleet-save as a small state machine

`TBot/Workers/FleetScheduler.cs`, `halfguru/internal/defender/escape.go`, `ogame-ninja`'s
`deploy_recall_fleetsave.go`, `kweimann/cruiser`, `PHPOgameBot`'s `AttackChecker` — **all verified**.

```text
on an inbound hostile fleet:  find the earliest arrival; ignore probe-only fleets; sort destinations by arrival
                              if now < arrival − window: schedule a wake at arrival − randint(min, max)
                              else: do not attempt a doomed save; check after impact
save:                         enumerate legal destinations × speeds; gate on fuel, slots and cargo;
                              take the first route that covers the required duration
recall:                       only if the return lands inside the safe window
```

Constants, all verified: Cruiser's window `120–180 s` before impact and its `10 s` and `1 s` guards;
TBot's defensive duration `inbound arrival × 1.30 + jitter`, its sleep save `wake − departure`, a recall
registered at **half** the required duration, and 200,000 deuterium deliberately left behind; ogame-ninja's
recall at `98 %–101 %` of half the flight and a fixed `SetRecallIn(4 h)` at night; halfguru's recall only
when the outbound leg is ≤ 600 s. Two named defects to avoid: Cruiser's backoff ignores **all** events for
up to 60 s, hostile ones included, and its recall predicate is documented as a remaining-time test but
implements an elapsed-time test.

**This is the pattern that lets gate 3 be satisfied at all**: TBot's `AutoFleetSave` ends with an explicit
"no destination found → you gonna get hit" branch. It is the only acknowledgement in the corpus that a
save can fail, and it is what makes a 100 % save rate avoidable rather than inevitable.

### P8 — Wake at the next event, not on a tick

`TBot/Helpers/RandomizeHelper.cs`, `PHPOgameBot/QueueConsumer.php`, `kweimann/cruiser`, `ogame-ninja`,
`trilogi77` — **all verified**.

```text
TBot:        nextWake = min(productionTime, transportArrival, returningExpedition) + jitter(20–50 s)
             buckets: SomeSeconds 20–50 s, AMinuteOrTwo 40–140 s, AboutFiveMinutes 4–6 min,
             AboutTenMinutes 9–12 min, AboutAQuarterHour 14–16 min, AboutHalfAnHour 25–35 min
             (its AboutAnHour bucket's upper bound reads 42,000,000 ms — a typo for 4,200,000)
PHPOgameBot: nextStart = max(resourceEta, buildSlotFree, fleetSlotFree, shipReturn)
Cruiser:     a scheduler heap of (time, priority) events; the defence wake is absolute, the poll periodic
ninja:       per-weekday sleep: Sun 23:00 + 10 h, Mon–Fri 21:00 + 8 h, Sat 23:00 + 10 h, plus 0–15 min jitter
trilogi77:   cycle 600–1500 s, actions 3–11 s apart, attack check 300–780 s, night watch every 25–45 min
```

Five projects, one conclusion, and it is the conclusion the bot-detection literature independently
reaches: a fixed period leaves a spectral peak even when it is jittered. Tracked as
[S3](../specs/gameplay-algorithms.md#sp3--wake-at-the-next-material-event-never-on-a-fixed-period) and
[H5](../specs/gameplay-algorithms.md#h5--what-makes-a-schedule-look-worse).

### P9 — Escalation rather than a fixed probe count

`TBot/Workers/AutoFarmWorker.cs` (**verified**): probes `NumProbes`, then `×3` in the `ProbesRequired`
state, then `×9` in `FailedProbesRequired`, and `NotSuitable` after that. `PHPOgameBot/app/utils/OgameMath.php`
(**verified**) instead solves the game's own espionage counter closed-form for the minimum probes that
reveal the desired fields. The closed form is better and is what
[N1](../specs/gameplay-algorithms.md#n1-probe-sizing) takes, with TBot's ladder as the fallback when the
host's thresholds are not reachable.

### P10 — Earliest availability as the maximum of independent blockers

`PHPOgameBot` (**verified**): `getTimeToProcessingAvailable` is a `max` over the resource ETA (including
in-flight resources), the building slot freeing, expedition and fleet slots freeing, and the return of
missing ships. This is P8's other half — it says *what* to compute the next wake from. Its weakness is the
head-of-line `break` in the consumer: one unaffordable command freezes its whole dependency group, and the
`fleet` group is every fleet action. **Take the max, drop the break.**

### P11 — An in-flight ledger

`ogame-infinity/src/util/needs.js` (**verified**): `need = max(target − onHand − inFlight, 0)`, with each
mission row classified — deployment credits the destination, a transport credits both ends, a returning
flight credits its destination, an attack against your own planet and an expedition are skipped, and only
flights that actually consumed resources debit the origin. It is the only accounting of its kind in the
corpus, and the module's own "always `on planet + in flight`" rule
([U4](../specs/gameplay-algorithms.md#u4--the-fleet-ledger)) is the same idea. OGameX's own fleet-mission
table already carries the fields it needs.

### P12 — Reserve before spending, and net the reserve against production

`trilogi77/ogbot/economy.py` and `config.py` (**verified**): a per-resource `savings_reserve`, reduced by
what production will deliver during the wait, so saving deuterium for a drive does not freeze surplus
metal and crystal. Its published defaults: `keep_resources_buffer 0.10`, `max_saving_hours_economy 4.0`,
`max_saving_hours_research 6.0`, `storage_fill_trigger_percent 0.90` lowered to `0.5` while capacity is
below a 1,000,000 target, `fusion_reactor_solar_offset 25`, `defense_batch_size 25`, `loot_percent 0.50`,
`min_loot_value 50,000`, `max_attack_targets_per_cycle 8`, `farming_attack_cooldown_hours 2.0`,
`farming_blacklist_days 7.0`, `espionage_max_probes 12`. Nothing else in the corpus reserves anything,
and every other project's "unaffordable → wait" loop is why their queues stall.

### P13 — Paired seeds and a lower-tail statistic

`peterradzisz/ogame-fleet-optimizer` (**verified**): every individual in a generation shares one seed, so
fitness differences come from composition and not luck; per-fleet streams are `base_seed + i × n_sims`;
percentiles are nearest-rank (`idx = int(pct × n)`); the sample ladder is 10 → 50 → 100 runs with
200–1,000 for final validation; the deadline is a wall-clock check *mid-batch*, so one generation cannot
overshoot the budget. Its hard constraint — reject a candidate outright below a 0.95 win probability —
cannot be certified at its own explore sample size, and that contradiction is the thing to avoid: count
losing runs instead. `klaasvp/trashsim-public` (**verified**) is the counter-example: `N` full simulations
with per-run records aggregated upstream, and a documented case where a mean-profit reading inverted by
60 M resources.

## Convergence table

| Pattern | Projects that have it | Where they disagree |
| --- | --- | --- |
| Payback ordering | trilogi77, halfguru, TBot, pyogame | weighting (`M+1.5C+2D` vs `(2.5,1.5,1.0)` vs production divisors `2.5`/`1.5`); stopping (none vs `min(168 h, 24 h×(1+avg/20))`) |
| Mine-offset ladder | pyogame (`−3/−7`), r4fek (`−2/−5`), barakis (caps 25/25/15), TBot (10/7/5 + 2/2 in the opening only) | the constants, which is why they are taste |
| Requirement closure | pyogame, trilogi77, TBot, halfguru | all four hardcode the graph |
| Predictive energy | pyogame (`×0.95`), r4fek (per-mine inequality), trilogi77 (full balance) | the margin |
| Storage trigger | halfguru 0.8 × capacity, trilogi77 0.90 (0.5 below target), ninja at capacity, pyogame when the cost exceeds capacity | three conventions, so the guides' "24–48 h of production" wins |
| Single-build guard | all seven engine projects | only trilogi77 guards the stale read |
| Wake at next event | TBot, PHPOgameBot, cruiser, ninja, trilogi77 | bucket boundaries |
| Save recall | TBot (half), ninja (98–101 % of half), halfguru (arrival + 30 s) | the fraction |
| Escalating probes | TBot; PHPOgameBot solves instead | ×3/×9 vs a closed form |
| Tail statistics | fleet-optimizer (P20, p05/p95), TrashSim (means), maximalcode (distribution) | means vs quantiles |
| **Deliberate failure** | **none** | — |
| **Return per hour of queue** | **none** | — |
| **Defender-uncertainty modelling** | **none** | — |
| **Fleet-slot economics** | **none** | — |

The last four rows are the honest gaps: three are things the module must invent and mark as ours, and one
(queue occupancy) must be measured before it is claimed.

## The hardcoded-knowledge inventory

This is the evidence for why gate 1 exists, not a criticism of the authors: eleven projects, the same
decision, and a tool that breaks when the game adds an object.

| Project | What is embedded | Where |
| --- | --- | --- |
| TBot | Numeric ids for every building, facility, research, ship and defence (`MetalMine = 1 … GravitonTechnology = 199, SmallCargo = 202 … Crawler = 217`), five lifeform species' object ids, the full price table with per-level growth factors, the build-time class mapping, a hand-written lifeform prerequisite dictionary, per-ship fleet points, max-level wiring by name, an expedition escort ladder, the opening mine ladder | `TBot.Ogame.Infrastructure/Enums/Buildables.cs`, `LFBuildables.cs`, `Models/Ships.cs`, `Includes/CalculationService.cs` |
| halfguru | Building base costs and factors, 16 research definitions with numeric ids (106–199), building and research prerequisite tables, id→field switches, a 13-ship stat table, id constants, default maximum levels (metal 30, crystal 28, deuterium 26, solar 26, fusion 20, robotics 10, shipyard 12, lab 12, nanite 5, storages 15) | `internal/builder/{roi,builder}.go`, `internal/constants/*.go`, `internal/defender/escape.go`, `internal/config/config.go` |
| barakis | The entire ordered list and every ceiling | `src/buildings.py` |
| pyogame | Base costs, powers (1.5/1.6/2.0), `requirements` lists, a literal tank-capacity table to level 20 | `pyogame/{constructions,technologies,ships,abstract/ogame_objs}.py` |
| r4fek | Id, mission, target and speed maps, ship list, a ~400-coordinate farm list, an expedition planet list | `bot.py`, `planet.py`, `config.ini`, `sim.py` |
| trilogi77 | Building and research cost and prerequisite tables, a laboratory-requirement map, two literal start orders (~40 and ~19 steps), a defence prerequisite chain and list | `ogbot/gamedata.py`, `startorder.py`, `economy.py` |
| cruiser | `Mission` 1–16, `Ship` 202–219, `Technology` 106–199, `Facility` 14–44, `Defense` 401–503, `CharacterClass` 1–3, and a full `SHIP_DATA` table carrying cost, requirements, drives, shield, weapon, capacity and rapid fire per ship | `ogame/game/{const,data}.py` |
| PHPOgameBot | Building, ship, research, defence, enhanceable and upgradable enums with ids, prices, growth constants and names; production and storage formulas; a 21 KB planet entity with a column per level and per unit count; and a filter that lists 7 defence plus 14 ship columns literally | `app/model/enum/*.php`, `app/model/ResourcesCalculator.php`, `app/model/entity/Planet.php`, `DatabaseManager::getNoFleetAndNoDefenseFilter()` |
| ogame-infinity | Ship and defence cost tables, mission-type and unit enums | `src/util/enum/*.js` |
| ogame-ninja | Object ids, literal build-step arrays, ship speeds, recall durations | `community/cremefresh55/*.go`, `official/*.go` |
| fleet-optimizer | A counter map from enemy ship type to its counter, and per-ship base attack values | `optimizer/greedy.py` |
| **jstar88/Ogame-algorithms** | **Nothing** — costs are function parameters | — |

`PHPOgameBot`'s filter deserves the last word: because it names 21 unit types as literal columns,
"inactive **and defenceless**" silently stops meaning defenceless the moment the host adds a ship, and
nothing in the tool can see that it stopped. That is the failure mode gate 1 prevents, observed in the
wild.

## What not to take

- **Hardcoded object, price or requirement tables.** Universal in this corpus and forbidden here.
- **A fixed build chain.** `trilogi77`, `ogame-ninja` and `halfguru` all ship one literal order for every
  account; the module derives order from the host's numbers and the persona's skill, which is also what
  makes accounts differ from each other.
- **Ignoring queue occupancy.** Nobody accounts for it; see P1's correction.
- **Responding to a mean.** A documented 60 M sign flip.
- **A win-probability constraint you cannot measure.** 0.95 at n = 50 is noise.
- **Unbounded simulation counts.** "Uncapped — it is your CPU" is the opposite of a budget.
- **Porting an expected-value engine.** `opbe` is O(1) and its own README says to validate against
  thousands of runs; that is a modelling choice, and ours is to sample and report a tail.
- **A fixed period with jitter on top.** The one thing every source, every project and the literature
  alike agree is the signature.
- **Config-shaped personas.** Mine offsets and level caps as configuration produce one behaviour with
  different numbers in a file; the module's personas are policy and their variety has to be observable.
- **A client-side tool of any kind.** None of the surveyed operation code — login, session, CAPTCHA,
  proxy, dispatch — is in scope, now or later.

## One honest gap, restated

Nobody in this survey implements failure on purpose: every save path is best-effort with a generous
window, and the single exception (`TBot`'s give-up branch) is a fallback rather than a plan. No project
models a mistake rate, and no source quantifies how often real players actually lose a fleet — the
celebrated "80 % of fleets are lost while offline" figure could not be found anywhere. Authenticity needs
a failure rate, so it comes from the persona design as a **placeholder** and is realised as named ordinary
mistakes ([V3](../specs/gameplay-algorithms.md#v3-the-save-that-fails)), with the eventual numbers taken
from our own pilot telemetry rather than from a statistic nobody can source.
