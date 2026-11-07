I now have exhaustive coverage of the repository. Here is the complete research document.

---

## Overview

`ogame-ninja/scripts` is the **public script repository for OGame Ninja Assistant (NJA)**, a third-party OGame automation platform. It is 100% Go (per GitHub language stats), 18 stars, 47 forks, 10 contributors, last commit ~1 year ago (`Update ipm.go`). The repo is **not the bot itself** — it is a library of user-runnable scripts executed *inside* the NJA runtime. Scripts call a platform API (`GetCelestial`, `GetSlots`, `NewFleet`, `CronExec`, `SendTelegram`, etc.) that the repo does not implement.

Two folders, as stated in `README.md`:

- `official/` — scripts maintained by the NJA project.
- `community/` — user-submitted scripts (cremefresh55, RockClubKASHMIR, ONSCH/RudeDude, Anderson Palma, CellMaster, etc.).

README lists 23 official scripts. The folder actually contains a few more not in the README (`sleep_schedule.go`, `auto_lifeform_techs.go`, `deploy_recall_fleetsave.go`, `repatriate_all.go`).

**Two execution dialects coexist** (important for anyone reusing this):

1. **NJA script language** (files at `official/*.go` and `community/**`): despite the `.go` extension these are **not valid Go** — no `package`, no imports, bare globals (`origin = "1:2:3"`), `func` with untyped params (`func shouldSkip(planetInfo)`), `for ... in` loops, `=` assignment inside `if`. They compile only in the NJA interpreter.
2. **Go VM mode** (`official/go_vm/*.go`): real Go (`package main`, `import "nja/pkg/nja"`, `"nja/pkg/ogame"`, `"nja/pkg/wrapper"`). These are compiled by the platform's Go VM. `go_vm/` mirrors most official scripts (`activities`, `abm_builder`, `ships_builder`, `auto_colony_builder`, `send_discovery_fleet`, `message_attackers`, `debris_field_watcher`, `spy_active_moons`, `watch_systems`, `clone_defenses`, `repat_att_ships`, `repatriate_all`, `find_master`, `private_chat_notifications`).

## Architecture & entry points

- **No executable, no `main` in the DSL scripts.** Each `.go` file is a top-to-bottom script run by NJA. Entry point = first statement. Long-running scripts end in `<-OnQuitCh` (block until user stops) or `for { ... }`.
- **Event-driven scripts** use platform channels: `<-OnSystemInfos`, `<-OnAttackCh`, `<-OnAttackDoneCh`, `<-OnAttackCancelledCh`, `<-OnChatMessageReceivedCh`, `<-OnTelegramMessageReceivedCh` (`message_attackers.go`, `handle_telegram_msg.go`, `private_chat_notifications.go`, `automatic-recycle-expedition-debris.go`).
- **Scheduled scripts** use `CronExec(spec, callback)`: `CronExec("@00h00", callback)` (`buy_offer_of_the_day.go`), `CronExec(starttime, farmer)` (`OptimalFarmer.go`), `CronExec("0 0 21 * * 1", ...)` (`sleep_schedule.go`), `CronExec(time, build_fleet)` (`FleetBuilder.go`).
- **Go VM scripts** have `func main()` and import `nja/pkg/nja` (plus `ogame`, `wrapper`).
- **No shared library code in this repo.** The only "import" in the DSL is `import("strings")` / `import("sync")` / `import("errors")` / `import("regexp")` — injected stdlib shims. The `nja` package itself lives in the (not present) NJA platform, not here.

## Scheduling & loop model

- **Busy-wait loops** with platform sleeps: `Sleep(ms)`, `SleepSec`, `SleepMin`, `SleepRandSec(a,b)`, `SleepRandMin(a,b)`, `SleepRandHour(a,b)`, `SleepRandMs(a,b)`.
- **Fixed-interval loops**: `checkInterval = 5` → `Sleep(checkInterval * 60 * 1000)` (`hunter.go`, `abm_builder.go`); `activities.go` loops every `Random(5*60*1000, 7*60*1000)`.
- **Cron scheduling** via `CronExec` with cron specs (`0 0 21 * * 1`) and shorthand (`@00h00`, `@15h10`, `@8h15`).
- **Flight-arrival-anchored loops**: `expeditions.go` computes `minSecs = Min(fleet.BackIn)` over expedition fleets, then `Sleep((minSecs + 10) * 1000)` — sleeps until the next fleet returns, not a fixed poll.
- **Termination**: `StopScript(__FILE__)` (RockClubKASHMIR), `Exit()`, `break`, `wg.Wait()` (`Standard_Defences.go` uses a `sync.WaitGroup` + goroutine per celestial).
- **Sleep-mode orchestration** (`deploy_recall_fleetsave.go`, `sleep_schedule.go`): `DisableNJA()` / `EnableNJA()`, `Logout()` / `Login()`, `StartSleepMode()` / `StopSleepMode()`, `IsLoggedIn()`.

## Decision engine & algorithms

There is **no shared planner or heuristic engine**; each script encodes one hand-written rule. Notable algorithms:

- **Opening build order** — `auto_colony_builder.go` (both dialects) and `AutoColonyBuilder.go` encode an ordered list `a = [SOLARPLANT, METALMINE, METALMINE, SOLARPLANT, METALMINE, METALMINE, SOLARPLANT, CRYSTALMINE, ... ROBOTICSFACTORY, RESEARCHLAB, SHIPYARD, ... ENERGYTECHNOLOGY, COMBUSTIONDRIVE, ... SMALLCARGO]` (42 steps). A map `m[oid]++` tracks "each occurrence = next level". `skipResearches = len(planets) > 1`.
- **Flight-time estimator** — `OptimalFarmer.go` `calc_highest_flight_time()`:
  ```
  Entfernung = system_distance * 95 + 2700
  speedship = LC: 7500 + 750*combustionlvl
              SC: 10000 + 1000*combustionlvl   (impulse < 5)
              SC: 10000 + 2000*impulslvl       (impulse >= 5)
  temp  = Entfernung * 10 / speedship
  time  = (3500 / Geschwindigkeitsfaktor) * Sqrt(temp) + 10
  time  = time / speeduni        // speeduni = server.Settings.FleetSpeed
  ```
- **Target scoring** — farmers sort targets by total looted resources descending using a hand-written bubble sort (`sort(array_res, array_koord)`).
- **Greedy affordability cascade** — `Standard_Defences.go` `buildefense()` tries full 5-defense bundle first, then 4, 3, 2, 1, then falls back to `res.Div(price)` of the best single type.
- **Master selection** — `find_master.go` `findCelestialWithHigherFleetValue()`: `ships.FleetValue(lfBonuses)` plus outgoing/incoming fleet value (`Origin==coord && Mission!=PARK` outgoing, `Destination==coord && Mission==PARK` incoming); picks max.

## Data model & persistence

- **No persistence in scripts.** All state is in-memory: `data = {}` bitmap (`watch_systems.go`), `attacks = {}` map keyed by `ArrivalTime.Unix()` (`message_attackers.go`), `visitedCoords` cache (`hunter.go`), `m` level-tracking map (`auto_colony_builder.go`), `waves`/`curentco` maps (`expedition_by_list_of_ships.go`). Restart = full reset.
- **Platform-owned state** is the real data layer: `GetCachedCelestial`, `GetCachedCelestials`, `GetPlanets`, `GetMoons`, `GetFleets`, `GetSlots`, `GetResearch`, `GetTechs`, `GetEspionageReportFor`, `GalaxyInfos`, `GetHighscore`.
- **Structured objects** exposed by the platform: `ogame.PlanetInfos` (fields `Activity`, `Inactive`, `Vacation`, `Banned`, `Player.Rank`, `Alliance`, `Debris.RecyclersNeeded`, `Moon.Activity`), `ogame.AttackEvent` (`AttackerID`, `ArrivalTime`), `ExpeditionDebris` (`Metal`, `Crystal`, `Deuterium`, `PathfindersNeeded`), ship/defense/resource records with `ByID(id)`, `FleetValue`, `Div`, `Sub`, `Mul`, `Gte`, `Total`.

## Config surface

**No config files, no env, no CLI flags.** Every script is configured by editing hardcoded top-of-file variables:

- Coordinates: `origin = "1:2:3"`, `target = "4:5:6"`, `master = "4:212:8"`, `homes = ["M:1:2:3"]`, `bidHome = "P:1:1:1"`, `planetsToBuildOn = ["1:1:1","2:2:2"]`.
- Numeric thresholds: `minActivity = 30`, `maxActivity = 20`, `spy2send = 2`, `nbr = 20`, `constructionTime = 17`, `standardrocket = 21000`, `deutToLeave = 1500000`, `min_player_rank = 3000`, `sys_radius = 20`.
- Maps/dicts: `toBuild = {LIGHTFIGHTER: 6, HEAVYFIGHTER: 4}`, `highestBids = {'bronze': 1000000, ...}`, `AUCTION_TIME_RANGES = {...}`.
- Scheduling strings: `starttime = "@19h58"`, `when = ["@8h15", "@15h43"]`, `myTime = "09:33:00"`.
- Notifications: `TELEGRAM_CHAT_ID` (platform global), `DISCORD_WEBHOOK`, `TelegramID`.

## Edge cases & failure handling

- **Slot exhaustion** is handled per-script: `if slots.InUse < slots.Total ... else SleepSec(30)` (`spy_*.go`); `checkFreeSlots()` waits for earliest `f.BackIn + Random(5,10)s` (`recycleOwnDF.go`); discovery waits `SleepRandMin(8,10)` on slot/resource errors (`send_discovery_fleet.go`).
- **Reserved slots**: respected by most (`GetFleetSlotsReserved()` subtracted in `send_discovery_fleet.go`, `OptimalFarmer.go`, `recycle_debris.go`, `Standard_Defences.go` `reservedslots=2`, RockClubKASHMIR scripts). **Explicitly ignored** in `expeditions.go` (`// WARNING: This script doesn't care about the "reserved slots"`).
- **Error strings matched**: `strings.Contains(err.Error(), "Maximum number of fleets reached")`, `"Not enough resources"` (`send_discovery_fleet.go`, `go_vm` version).
- **Input validation**: coordinate parse errors logged + `continue` (`hunter.go`); `minActivity <= 15` rejected (`hunter.go`); `fromSystem`/`toSystem` range checks and clamping to `1..499` (`recycle_debris.go`, farmers); system clamp `SYSTEMS` (`spy_*.go`); `AutoColonyBuilder.go` has a duplicate/missing step-number checker that throws via `metalmine[100000]`.
- **Build verification** (strongest error handling in repo): `auto_colony_builder.go` after each build re-reads `ConstructionsBeingBuilt()` / production line and waits `buildingCountdown + 10`; on mismatch `LogError(... "wait 1min")`.
- **Retry loops**: `auto_lifeform_techs.go` retries with `SleepRandMin(5,6)` / `SleepRandHour(1,2)`; `ipm.go` prints error but loops 30 times regardless.

## Anti-detection & authenticity

The repo's explicit, intentional humanization measures:

- **Randomized jitter sleeps everywhere** (`SleepRand*`): 0.25–2 s between spy probes, 1–2 min after spy before attack, 3–6 s between attacks, 2–3 min between farm planets (`OptimalFarmer.go`); 500–1000 ms between galaxy scans "for avoid ban" (`recycle_debris.go`); 13–18 s before sending expedition fleets "For avoiding ban" (`expedition_by_list_of_ships.go`).
- **`sleep_schedule.go`** — the bot disables itself at night on a per-weekday cron (Sun/Sat 23h for 10h, Mon–Fri 21h for 8h) with a 0–15 min jitter. This is the strongest "human sleep pattern" simulation in the repo.
- **`activities.go`** — generates fake activity by calling `GetFacilities()` on a random celestial every 5–7 min. (See Notable concerns.)
- **`message_attackers.go`** — sends a random casual message (`"hey online :)"`, `"o/ Online."`, `"im here :("`) to each new attacker, mimicking an alert human defender.
- **`deploy_recall_fleetsave.go`** — logs out, recalls mid-flight with jitter, logs back in near landing; hides 24/7 online presence.
- **`OptimalFarmer.go`** — `use_shuffle` randomizes farm-planet order; comment "Shuffles the order ... to make it more human"; `attack_if_last_active` avoids hitting recently-active targets.
- **`hunter.go`** — polls activity every 5 min to detect when a target goes offline (a hunter's behavior, but the 5-min polling itself is bot-like).

## Discrete mechanisms

- M01 — Hunter offline detection — `0 < Activity < minActivity` (default 30, must be >15) on planet OR moon flags "activity detected", else "no activity in last X min"; check every `checkInterval`=5 min (`official/hunter.go`).
- M02 — Spy only *active* moons — moon with `15 <= Moon.Activity <= maxActivity` (20), send `spy2send`=2 probes, system range 1..299, positions 1..15, wait 30 s if no free slot (`official/spy_moons_activity.go`).
- M03 — Spy *all* moons in range — no activity filter, only skip inactive/vacation/ignored; positions 1..15 (`official/spy_active_moons.go`).
- M04 — `shouldSkip(planetInfo)` — skip nil, name in `playersToIgnore`, alliance in `alliancesToIgnore`, `Inactive`, `Vacation` (`official/spy_*.go`).
- M05 — Fill expedition slots — `expeditionsPossible = ExpTotal - ExpInUse`; random system in `[minSystem,maxSystem]`, position 16, speed 100%, duration 1 h, `ships = {LIGHTFIGHTER:2, LARGECARGO:3}`; sleep 10–20 s between sends; then sleep until `minSecs+10` (`official/expeditions.go`).
- M06 — ABM topping — `possibleABM = MissileSilo*10 - ipm*2 - abm`, build that many, every 5 min (`official/abm_builder.go`).
- M07 — Defense cloning — `delta = masterDef.ByID(id) - slaveDef.ByID(id) - inQueue`, build `delta` if >0, every 1 h (`official/clone_defenses.go`).
- M08 — Scheduled repatriation — at cron times, TRANSPORT all resources, `lc=1000, sc=1000`, sleep 1–4 s between slaves (`official/repatriate.go`).
- M09 — Repatriate everything — for every non-master celestial, `CalcFastCargo(lc, sc, resources.Total())`, send all resources, `SleepRandSec(1,4)` (`official/repatriate_all.go`).
- M10 — Repatriate attack ships — PARK all of `[LF, HF, CR, BS, BOMBER, DESTROYER, DEATHSTAR, BC]` to master (`official/repat_att_ships.go`).
- M11 — Build ship quota — `canBuild = resources.Div(GetPrice(unitID,1))` capped at `nbr`; decrement map; 5-min loop until map empty (`official/ships_builder.go`).
- M12 — IPM volley — 30 iterations; build 20 IPM; `SleepSec((constructionTime+1)*nbr)` = `(17+1)*20`; `SendIPM(planet.ID, target, nbr, 0)` (`official/ipm.go`).
- M13 — Night fleetsave — moon→moon PARK at `TEN_PERCENT`, all resources + all ships, `SetRecallIn(4h)` (`official/night_fleet_save.go`).
- M14 — Deploy-recall fleetsave — leave `deutToLeave=1500000`; `deutToTake = Available - leave`; send `SetDeuterium(deutToTake - fuel)`; `half = ArriveIn/2`; recall at `Sleep(Random(half*980, half*1010))` ms; re-login at `Sleep(Random(half*800, half*900))` ms; full Logout/Login cycle (`official/deploy_recall_fleetsave.go`).
- M15 — Weekly sleep — cron Sun/Sat 23h×10h, Mon–Fri 21h×8h; 0–15 min jitter; `DisableNJA()` → sleep → `EnableNJA()` (`official/sleep_schedule.go`).
- M16 — Fake activity — random celestial `.GetFacilities()` every 5–7 min (`official/activities.go`).
- M17 — Message new attackers — on `OnAttackCh`, if attacker ID unseen, send random of 8 msgs; map keyed `ArrivalTime.Unix()`; delete on Done/Cancelled (`official/message_attackers.go`).
- M18 — Discovery fleet — `totalSlots = Total - InUse - Reserved`; `GetSystemsInRangeAsc(system, 10)`; `CoordinatesAvailableForDiscoveryFleet`; on error strings wait 8–10 min; 1–2 s between sends (`official/send_discovery_fleet.go`).
- M19 — Recycle expedition DF — on `OnSystemInfos`, if `PathfindersNeeded >= nbPathfindersMin` (10), send `n` pathfinders to `system:16`, skip if already recycling that DF (`official/automatic-recycle-expedition-debris.go`).
- M20 — Scripted colony build — step list `a[]`; `m[oid]++` per occurrence = target level; skip research if `len(planets) > 1`; verify in production line; wait `buildingCountdown + 10` (`official/auto_colony_builder.go`).
- M21 — Lifeform tech tree — 6 wanted tier-1 techs; own-race tech selected directly, else artifacts (≥200) else random; `FreeResetTree()` on mismatch; 1–2 h retry loop (`official/auto_lifeform_techs.go`).
- M22 — Offer of the day — `CronExec("@00h00")`, sleep 3–5 min, `BuyOfferOfTheDay()` (`official/buy_offer_of_the_day.go`).
- M23 — DF gone alert — poll `GalaxyInfos` every 2 s until `Debris.RecyclersNeeded == 0`, then Telegram (`official/debris_field_watcher.go`).
- M24 — System change watch — per-system bitmap of occupied positions 1..15, compare vs previous, Telegram on diff, every 5–10 min (`official/watch_systems.go`).
- M25 — Telegram command bot — `msg <player_id> <text>` and `msga <alliance_id> <text>`, plus reply-to forwarding of OGame messages (`official/handle_telegram_msg.go`).
- M26 — Chat→Telegram forward — every chat message to Telegram + `LogWarn` (`official/private_chat_notifications.go`).
- M27 — Highscore crawl — `GetHighscore(1,1,page)` for pages 1..`NbPage`, `SleepRandMs(100,200)` (`official/highscore_crawler.go`).
- M28 — Farmer scan — `sys_radius` (or per-planet lower/upper ranges), clamp to 1..499, positions 1..15; select `Inactive && rank < min_player_rank && !Vacation && !Banned` (`community/cremefresh55/OptimalFarmer.go`).
- M29 — Farmer zero-defense filter — target must have NO RL/LL/HL/Gauss/Ion/Plasma/shields and NO fleet ships/cargo/colony/recycler; optional `LastActivity > attack_if_last_active` (`OptimalFarmer.go`).
- M30 — Farmer attack sizing — `attacks_to_make = Total - InUse - Reserved`; bubble-sort targets by loot desc; cargo `CalcFastCargo(lc, sc, res/2)` + `Round(sc*additional_cargos/100)` extra small cargo (`OptimalFarmer.go`).
- M31 — Farmer pacing — after attacks, `Sleep(highest_flight_time*2*1000)` (round-trip of farthest target), then 2–3 min before next planet (`OptimalFarmer.go`).
- M32 — Defense standard — targets rocket 21000 / LL 4000 / HL 2000 / Gauss 400 / Plasma 200; require Nanite ≥6, Shipyard ≥8, reserve 2 slots, preserve 10M deut; 5-tier affordability cascade; 20–40 min between passes (`community/Standard_Defences.go`).
- M33 — Auction bidding — max bid bronze 1M / silver 2.5M / gold 5M / platinum 10M; skip forbidden words & whitelisted highest bidders; bid `MinimumBid - AlreadyBid`; refresh interval by remaining time & category (see Config) (`community/auction.go`).
- M34 — Galaxy debris sweep — origin = celestial with most recyclers; send if `RecyclersNeeded > Rnbr`; `nbr = min(needed, available)`; 500–1000 ms between scans; `times` full sweeps (`community/RockClubKASHMIR/recycle_debris.go`).
- M35 — Expedition DF recycle — ignore if `PathfindersNeeded < Pnbr` (5); send only the missing pathfinders (`abr = pp - already_sent`); `PauseBetweenRepeats`=30 min between sweeps (`community/RockClubKASHMIR/recycle_expedition_debris.go`).
- M36 — Expedition by ship list — `shipsList` with 0 = auto-split (`Floor(fleetInAir/times)`, 40% floor guard); `DurationOfExpedition` 1–8 h; 13–18 s pre-send delay; respects reserved slots; cycle repeat limit (`community/RockClubKASHMIR/expedition_by_list_of_ships.go`).
- M37 — Recycle own DF — per planet, `rn = Debris.RecyclersNeeded`; send `min(rn, available)`; wait `BackIn + 2–5 s` between waves (`community/ONSCH/recycleOwnDF.go`).
- M38 — Probe transport — repeat N times, send all of one resource via probes, `waittime`=240 s round-trip, 5–10 s jitter (`community/TransportWithProbe.go`).
- M39 — Multi-planet ship builder — every 30–60 min, `canBuild = resources.Div(price)` capped at quota (`community/ships_builder_all_planets.go`).
- M40 — Step-table colony builder — 42-step Quick-Start order arrays (solar/metal/crystal/deut/robo/shipyard/lab/energy/combustion/small-cargo); duplicate/missing step checker (`community/cremefresh55/AutoColonyBuilder.go`).
- M41 — Auto storage — build next storage when `Available >= StorageCapacity`, every 10 min (`community/cremefresh55/AutoBuildStorage.go`).
- M42 — Scaled defense rebuild — per-planet `factors[]` multiply desired counts; build delta; always 1 of each shield dome (`community/cremefresh55/AutoDefBuilder.go`).
- M43 — Spy-probe restock — keep `spy_probes_desired`=50 per planet, check 10 min, only when production line empty (`community/cremefresh55/AutoBuildSpyProb.go`).
- M44 — Exact fleet build — build fixed ship counts once or via `CronExec(time, build_fleet)` (`community/cremefresh55/FleetBuilder.go`).
- M45 — Build-then-cancel — build `FUSIONREACTOR`, `Sleep(buildingCountdown - 60)`, `CancelBuilding()` (`official/build_cancel.go`).
- M46 — Highest-value fleet master — `FleetValue(lfBonuses)` + incoming(PARK)/outgoing(non-PARK) fleets (`official/find_master.go`).

## Notable concerns

- **Gate-1 (hardcoded object universe) — flag HIGH.** Nearly every script hardcodes object IDs, coordinates, and build orders as config: `auto_colony_builder.go` `a = [SOLARPLANT, METALMINE, ...]`, `AutoColonyBuilder.go` step arrays, `repat_att_ships.go` `attShips = [LIGHTFIGHTER, ...]`, `auto_lifeform_techs.go` `wantedTier1 = [...]`, `expeditions.go` `ships = {LIGHTFIGHTER:2, LARGECARGO:3}`, `ipm.go` `constructionTime = 17`, every `origin = "1:2:3"`. Prices, by contrast, are correctly derived dynamically via `GetPrice(unitID, 1)` and `resources.Div(price)` — the repo gets "kinds/prices read at runtime" right for costs, but treats IDs/orders as literals.
- **Gate-2 (over-engineering) — flag MEDIUM-HIGH.** `auction.go` is the canonical offender: 6-language item-name dictionary, regexp name cleaning, 5 notification flags, per-tier bid-timing map, 3 changelogs of commentary — far more machinery than the rule it enforces. `OptimalFarmer.go` duplicates a bubble sort and a flight-time formula the platform already provides (`FlightTime()` is used elsewhere in the same file for the home-transport path). `Standard_Defences.go` reinvents resource affordability as a 6-branch cascade.
- **Gate-3 (non-human behavior) — flag MEDIUM.** `activities.go` fakes activity by polling `GetFacilities()` on a random celestial every 5–7 min — no human does this; it is pure detection-noise. `expeditions.go` deliberately ignores reserved slots. Most scripts act on fixed 5-min intervals with near-zero variance, which is measurable. The strongest authenticity assets are `sleep_schedule.go` (nighttime offline), `deploy_recall_fleetsave.go` (login/out cycle), and the randomized delays in the farmers.
- **Correctness bugs observed**: `expedition_by_list_of_ships.go`/`recycle_expedition_debris.go` contain `== 0` where a boolean assignment is intended (`ExpsTemp == 0`), stale `else`-free control flow with `home = len(homes)-1` as a loop-exit trick, and `System` index off-by-one resets (`curSystem = system-1`). `TransportWithProbe.go` requires a manually-entered round-trip time.
- **No tests, no CI config, no `go.mod`, no docs beyond README.** This is a script dump, not an engineered project.

## Confidence

- **High** for the existence, file paths, constants, and formulas quoted above — all extracted from raw source via repository search and the README.
- **Medium** on the exact complete file inventory: the README omits several files present in the folder (`sleep_schedule.go`, `auto_lifeform_techs.go`, `deploy_recall_fleetsave.go`, `repatriate_all.go`, and the `go_vm/` tree). The `official/` and `community/` listings above are derived from live search results, not a full directory listing, so a few additional community scripts may exist beyond those named.
- **Low** on NJA platform API semantics (units of `BackIn`/`ArriveIn`, `Sleep` argument units, `GetProduction()[0]` indexing) — these are inferred from usage; the `nja` package source is not in this repo.