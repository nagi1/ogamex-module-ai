# What automation tools already solved

Research note, 14 September 2026. Public OGame automation projects were read for their **decision
algorithms** — planning, ordering, accounting and scheduling — as engineering evidence for the
in-product AI and the offline simulator. No client automation, scraping, session or evasion technique was
collected or is to be used: automating an official OGame account is forbidden by the game's rules and
terms, which is also why none of this may leave the module's own universe.
[OGame rules §6](https://en.ogame.gameforge.com/ajax/main/rules)

## Why this is worth reading at all

These projects are unfashionable but not naive. Several of them solve exactly the problems this module
now has — what to build next, when to fix energy, when to save, which farm pays — with a few hundred lines
of arithmetic and no model of any kind. The recurring shapes are worth adopting; the way most of them
*store* game knowledge is exactly what [gate 1](../specs/cognition-gates.md) forbids.

## The projects surveyed

| Project | Language | Driver | What is worth reading |
| --- | --- | --- | --- |
| [`halfguru/ogamebot`](https://github.com/halfguru/ogamebot) | Go | poll loop + SQLite | The richest decision engine, and the only one built for OGameX: ROI ordering, build loop with jitter, farm evaluation, fleet-save route scoring |
| [`ogame-tbot/TBot`](https://github.com/ogame-tbot/TBot) | C# | worker loops | `CalculationService` days-of-investment-return, an explicit energy-source ladder, per-technology caps, scheduler that sleeps until the next arriving fleet |
| [`ogame-ninja/scripts`](https://github.com/ogame-ninja/scripts) | Go | cron + sleeps | Literal ordered build arrays with per-level counters, an optimal-farmer that ranks by loot, deploy-and-recall fleet save, a weekday sleep schedule |
| [`PiecePaperCode/barakis`](https://github.com/PiecePaperCode/barakis) | Python | single loop | A priority list of `(action, condition)` records — the smallest readable form of a build policy |
| [`jaesivsm/pyogame`](https://github.com/jaesivsm/pyogame) | Python | tick loop | **Requirement closure** as a recursive generator, and a predictive energy gate |
| [`kweimann/cruiser`](https://github.com/kweimann/cruiser) | Python | sleep loop | Fleet-save-first scheduling with a reaction window and a recall-if-return-is-short rule |
| [`r4fek/ogame-bot`](https://github.com/r4fek/ogame-bot) | Python | interval loop | Config-shaped policy: mine level offsets, minimum energy level, static farm list |
| [`trilogi77/OgameBot`](https://github.com/trilogi77/OgameBot) | Python | browser-driven | Test names alone hint at savings reserves, storage priority and transport windows |
| [`ogame-infinity/web-extension`](https://github.com/ogame-infinity/web-extension) | JS | userscript | Per-planet "needs" minus what is already in flight — an inventory that includes the fleet ledger |
| [`jstar88/Ogame-algorithms`](https://github.com/jstar88/Ogame-algorithms) | PHP | library | The published loot split and ACS capacity weighting as code |
| [`jstar88/opbe`](https://github.com/jstar88/opbe) | PHP | library | Probabilistic battle simulation |

## The recurring patterns

Each pattern below is what more than one project independently converged on.

### P1 — Marginal payback ordering

Seen in `halfguru/ogamebot` (`internal/builder/roi.go`), TBot (`GetNextMineToBuild`) and stated in
words by veteran guides ([veteran play](veteran-play.md#1-the-opening-and-how-it-is-really-decided)).

```text
for each candidate upgrade:
    cost   = host price of the next level
    gain   = host production at level+1 - host production at level
    value  = cost.metal + 1.5 * cost.crystal + 2.0 * cost.deuterium
    score  = gain / value
take the best; re-evaluate after every completion, because the ranking moves
```

The weights are the accepted trade band, which is also how players compare costs. This is the
continuous-knapsack optimum for a single budget, and it is what the module's economy order should be.

### P2 — Priority list with affordability and cap gates

Seen in `barakis`, `pyogame`, TBot's research caps and `r4fek`'s level offsets.

```text
for entry in authored_priority_list:
    if not entry.condition(state): continue
    if level(entry.item) >= entry.cap: continue
    if not affordable(entry.item): continue
    return entry.item
```

Cheapest policy pattern that exists, and honest about what it is: authored taste, not derivation. It is
fine for persona *preferences*; it is not fine as the only reason a capability is reachable.

### P3 — Requirement closure

Seen in `pyogame`'s `planner_next_plans` / `requirements_for`, TBot's `GetLFBuildingRequirements`, and
`halfguru`'s building and research prerequisite tables. The pattern:

```text
expand(want):
    for requirement in host.requirements(want):
        if level(requirement) < requirement.level: yield from expand(requirement)
    if want.level > current_level(want) + 1: yield from expand(want.at(level - 1))
    yield want
```

**The pattern is right and the storage is wrong.** Every one of these projects hardcodes the requirement
tables, so the tool breaks when the game adds an object; the module must compute the same closure from
the host's own requirement graph, which is what `FacilityChain` does.

### P4 — Threshold rule, including the energy gate

Seen in `halfguru` (`Energy < 0`), TBot's energy ladder, `barakis` (`energy < 0`) and, most usefully,
`pyogame`, which is *predictive*: "if the cost energy times 0.95 exceeds current energy, build the solar
plant instead".

```text
if planet.energy < 0: return cheapest_energy_source(planet)          # reactive
next = best_payback_target(planet)
if next.energy_cost * 0.95 > planet.energy: return solar_plant       # predictive
return next
```

The module takes the predictive form, because it is what the majority of guides describe and it
reproduces the canonical opening (see [veteran play §2](veteran-play.md#2-energy--the-one-place-the-sources-genuinely-disagree)).

### P5 — One action, then sleep until the slot frees

Seen in `halfguru` (`requireFreeBuildSlot`), TBot (sleep `countdown + jitter`), `ogame-ninja`
(`SleepSec(countdown + 10)`) and `pyogame` (one construction per idle planet).

```text
if planet.has_active_construction: return
issue(build)
schedule(next_attempt, now + production_time(build) + jitter)
```

This is what keeps a bot's request rate shaped like a player's, and on our profile it is simply a delayed
job rather than a loop.

### P6 — Raid profit accounting

Seen in `halfguru`'s `farmer.go`, `ogame-ninja`'s `OptimalFarmer.go` and TBot's `AutoFarmWorker`.

```text
for report in espionage_reports:
    if report.shows_defence or report.shows_fleet: continue
    loot = 0.5 * (metal + 1.5 * crystal + 2.0 * deuterium)     # 50 % plunder rule
    fuel = base_fuel * cargos * (distance / 35000) * speed_factor
    net  = loot - fuel
keep reports with net >= threshold; attack the best, reserving fleet slots
```

Matches the official raider guidance ("loot at least three times the deuterium spent").

### P7 — Fleet-save as a small state machine

Seen in `halfguru`'s `defender/escape.go`, `ogame-ninja`'s `deploy_recall_fleetsave.go` and `cruiser`.

```text
on hostile_fleet(destination = ours, eta):
    if eta - now < safety_margin: return            # too late to react, and that is realistic
    delay = random(reaction_min, reaction_max)
    schedule(save, now + delay)

save: rank legal routes by exposure, fuel and opportunity; send the best
      optionally schedule a recall at a fraction of the flight, if the return fits
```

The recall branch being optional is exactly the "a save can also fail" behaviour authenticity needs.

### P8 — Tick loop with jitter and a sleep window

Seen in `halfguru` (`interval + rand.Intn(interval/2)`), TBot (`ShouldSleep`, randomised intervals),
`ogame-ninja` (`sleep_schedule.go`, per-weekday cron, 8–10 h asleep) and `cruiser` (`sleep_min/max`).

```text
while true:
    if outside(session_window): sleep until window_start + jitter
    work()
    sleep(base_interval + random(0, base_interval / 2))
```

Never a fixed period. This is the pattern that produces an uptime curve at all, and the module's routine
planner is the equivalent.

## What not to take

- **Hardcoded object or requirement tables.** Universal in these projects and forbidden here.
- **A fixed build chain.** Several tools ship one literal order for every account; the module derives
  order from the host's numbers and the persona's skill, which is also what makes accounts differ.
- **Ignoring queue occupancy.** None of the surveyed ROI orderers account for the queue time a long build
  consumes, so they happily start a 6-hour upgrade when a 20-minute one with a better hourly return is
  available. The module should compare *return per hour of queue*, not return alone.
- **Config-shaped personas.** Mine offsets and caps as configuration produce one behaviour with numbers
  in a file; the module's personas are policy, and their variety has to be observable.

## One honest gap

Nobody in this survey implements failure on purpose: every fleet-save path is best-effort with generous
windows, and no project models a mistake rate. Authenticity needs that, and it has to come from the
persona design rather than from any tool reviewed here.
