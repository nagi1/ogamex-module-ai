# Enhancement plan — trilogi77/OgameBot

## Verdict summary (one line)
Study the ideas only: the repo is a hardcoded-universe scraper, so we adopt a handful of named player behaviours as our own host-read mechanisms, enhance four existing planners, and refuse every hardcoded table and machine-shaped behaviour.

## ADOPT-IDEA — table
| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Debris recycling (M35) | Send recyclers to a debris field after a raid, sized `ceil(debris/effective_cargo)` | New `Domain/Decision/QueueableRecyclePlanner` + `QueueableRecycle` + `Actions/QueueAiRecycleAction` + `Contracts/QueueAiRecycle`, bound in `AIServiceProvider` | A fleeter's raid loop is incomplete without collecting the debris it just made; recycler is host-read (civil hull with cargo, host `RecycleMission`), and the module currently has no recycling capability at all. Ordinary, nameable play. |
| Moonshot (M37) | Create a moon by farming debris to `moon_target_debris` via a sacrificial own fleet battle | New `Domain/Decision/QueueableMoonshotPlanner` (or a branch in the recycle planner) | Moons are a fleeter's fleetsave anchor; ship costs and debris factor are host-read, so gate 1 holds. Lower priority — gate it on persona (Fleeter/Turtle archetype only). |
| Per-target farming cooldown (M27) | Skip a target already hit within `farming_attack_cooldown_hours` | `app/Domain/Decision/RaidPlanner.php` | Stops the same farm being hit every session (a machine signature); reads the last raid timestamp from the host's own fleet-mission history. |
| Raid outcome blacklist (M25) | Skip a target that underperformed (≥3 raids, avg real loot below floor) for N days | `app/Domain/Decision/RaidPlanner.php` + record in `AiExperienceCaseFamily` | Turns raid outcomes into taste over host data (gate 1 allows it); closes the loop where a repeatedly bad target keeps passing the P20 estimate. |
| Metal-dump research (M09) | Spend overflow metal on a cheap technology while saving | `app/Domain/Decision/FacilityChain.php` (post-requisite branch) | "Spare metal into armour tech" is nameable; keeps the queue warm without a hardcoded object — candidate must still come from the host catalogue. |

## ENHANCE — table
| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `QueueableFleetSavePlanner` deploys to another own body, moon-first, no speed/offline control | Speed sweep 10–100%, pick shortest flight ≥ `offline_hours`; `recall_halfway` targets `offline/2` at 10% (M31) | Add absence-aware speed selection: the save must land ≥ the upcoming absence, with a `recall_halfway` recall scheduled when absence is long. Still own-planet deploy; speeds/offline are our own arithmetic over host times | `app/Domain/Decision/QueueableFleetSavePlanner.php`, `app/Domain/Decision/QueueableFleetSave.php`, `app/Domain/Decision/QueueableRecall.php` |
| `QueueableExpeditionPlanner` sends one smallest civil ship to slot 16, no rotation | Expedition system rotation 0,+1,−1,+2,−2 mod 499 (M32); cargo sized to max-find (M33/M34) | Rotate the expedition system per account/seed and size the cargo hull to the host's max-find table read from server settings; keep one-hull fleet | `app/Domain/Decision/QueueableExpeditionPlanner.php`, `app/Domain/Decision/QueueableExpedition.php` |
| `QueueableColonyPlanner` takes the first empty host-reachable slot | Colony scoring by fields + temperature (M38) | Rank candidate empty slots by host-reported fields and position temperature; keep the bounded scan and per-account seeded walk | `app/Domain/Decision/QueueableColonyPlanner.php` |
| `QueueableUnitPlanner` builds defence only reactively under attack | Proactive defence build, lowest completion % first, domes capped (M45) | Add a proactive defence branch when the persona's exposure band is crossed (not only `underAttack`); defence object stays host-read | `app/Domain/Decision/QueueableUnitPlanner.php` |
| `QueueableUnitPlanner`/`RaidPlanner` never learn from a real raid's actual loot | Real combat loot attributed per target (M49) | Record post-raid loot/debris through the existing `ExperienceEngine`/experience rules so the P20 screen is re-weighted by what actually landed | `app/Domain/Decision/RaidPlanner.php`, `app/Actions/ExecuteAiIntentAction.php`, `app/Domain/Experience/` |

## ALREADY-DONE (confirmed by grep)
- **Mine payback + adaptive cap (M01/M02)** — `app/Domain/Decision/EconomyUpgrades.php` (`PAYBACK_BASE_HOURS=48`, `PAYBACK_CAP_HOURS=168`, `LEVELS_PER_SLACK=20`).
- **Storage-first + overflow trigger (M03)** — `EconomyUpgrades::storage()` / `spendSurplus()`; capacity is host-derived, absence-driven (`absenceHours()`).
- **Energy deficit → cheapest capacity (M05)** — `app/Domain/Decision/EnergyCapacity.php`.
- **Early robotics/shipyard/lab via prerequisite chain (M06/M07/M14 replacement)** — `app/Domain/Decision/FacilityChain.php` (host recursive requirements + mission-required research; names nothing).
- **Save-for-build / savings reserve (M10/M11)** — `app/Domain/Decision/ReserveFloor.php` (10% buffer, 4h/6h horizons).
- **Combat engine + Monte-Carlo (M19/M20)** — `app/Infrastructure/Battle/NativeRaidEstimator.php` samples the host `PhpBattleEngine` (50 screen samples, P20); explicitly not a second engine.
- **Farm score / loot tier / fuel test / bashing limit (M22/M23)** — `app/Domain/Decision/RaidPlanner.php` (`BASHING_LIMIT=6`, `LOOT_TIER_FARM`, `roundTripFuel`).
- **Attack escape / reactive save + the save that fails (M41 + gate-3 failure)** — `app/Domain/Perception/PlayerObservationService.php` (`inboundThreat`, `reactionWakeAt`), `QueueableFleetSavePlanner`, `app/Domain/Decision/SaveFailurePolicy.php`.
- **Own-fleet / material-event recheck (M43)** — `app/Domain/Scheduling/SessionDecisionService.php` (`nextMaterialEventWake`, right-skewed arrival delay).
- **Feed / inter-planet transfer (M47)** — `app/Domain/Decision/QueueableTransferPlanner.php`.
- **Auto-fleet roles: cargo, colony ship, probe, escort (M21/M46 partial)** — `app/Domain/Decision/QueueableUnitPlanner.php`.
- **Scouting with fresh-intel TTL (M24 partial)** — `app/Domain/Decision/QueueableSpyPlanner.php`.

## REFUSE — list with one-line reason
- **M12 server start order / M13 new-colony order / M14 research unlock tree** — hardcoded object-name lists; gate 1 forbids exactly this (we already derive them in `FacilityChain`).
- **M26 suicide probe to spawn debris** — machine-shaped, no human sends a probe to die to create a debris field; gate 3.
- **M41 panic-build dumping all spendable into defence <5 min from impact** — machine-shaped reaction; gate 3.
- **M39/M40 IPM missile farming** — a second attack surface + weaponized automation we neither ship nor need; gate 2/3.
- **Night micro-wakeups on a 25–45 min clock / `_night_sweep`** — machine cadence; our `SessionPlanner` already models human uptime/absences.
- **M44 sliding rate limiter / action delay** — our executor is scheduled sessions, not a continuous loop; no continuous action stream exists to throttle.
- **M50 directive-claim loop / M51 auto planet-rename** — scripted directive farming is machine-shaped; gate 3.
- **M17/M18/M28/M29/M30/M36 formulas (times, distance, fuel, slots)** — the host already computes these; reimplementing them in PHP is forbidden duplication.
- **M48 spy-ledger Telegram/CSV** — we already persist reports and have the review loop (`app/Domain/Review/`); no Telegram channel.
- **Hardcoded `gamedata.py`/`client.py` selector layer** — the repo's whole object universe and DOM selectors are the anti-pattern to copy; gate 1 + no host-side daemon.

## Priority recommendation — the single highest-value change
Ship **debris recycling** (`QueueableRecyclePlanner` + `QueueAiRecycleAction` + `QueueAiRecycle` contract) as the next capability. It is the only genuinely *missing* ordinary fleeter behaviour left in the loop — every other raid-adjacent idea is an enhancement to a planner we already run. It is small: one new planner that asks the host for the recycler hull (host-read, gate 1), one work kind, one queue gateway, reusing the existing `CandidateActionFactory`/`QueueAi*` seam pattern. Gate 3 names it directly ("send recyclers to the debris after a raid"), and it makes the raid loop self-consistent instead of leaving free loot on every target we hit.

## Open questions / risks
- **Host recycle mission availability** — verify the host exposes a recycle mission and a debris-field reader (the module only imports `DeploymentMission`, `AttackMission`, `EspionageMission`, `ColonisationMission`, `TransportMission` today). If the host lacks a debris API, recycling is blocked on host work, not module work.
- **Debris data source** — the repo reads debris from a scraped page; we must find the host's own answer (fleet-mission aftermath or a planet debris service) so we never estimate debris in module code.
- **Moonshot needs a battle source** — a sacrificial fleet battle implies a host-side attack against our own body or a neighbour; confirm the host battle engine supports self-inflicted debris before committing.
- **Blacklist/cooldown must not hardcode a target** — keys must be host coordinates + host timestamps, with the window as persona flavour, never an object id.
- **Expedition max-find table (M33)** is a hardcoded value table in the repo; we must read the expedition-find ceiling from host server settings, not copy the table, or the idea fails gate 1.
- **Scope** — everything above stays in `Modules/AI`; no second battle engine, no host-side daemon, no new dependency, no new config for a constant.