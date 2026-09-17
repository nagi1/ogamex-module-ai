# Enhancement plan — r4fek/ogame-bot

## Verdict summary (one line)
A 2018 Python-2 screen-scraper whose only transferable value is the single idea of harvesting battlefield debris; the one other idea it had worth keeping (a 50k donor-skip floor) is already shipped in `QueueableTransferPlanner`, and everything else is hardcoded-universe, machine-shaped, or obsolete.

## ADOPT-IDEA

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Debris harvest (M24) | After a battle, send recyclers to the debris field to collect the floating resources before they vanish | New: `Domain/Decision/QueueableDebrisCollect.php` + `QueueableDebrisCollectPlanner.php`, a `QueueAiDebrisCollect` seam + action, `AiCandidateActionType::DebrisCollect` | Harvesting debris is the most routine post-battle play in OGame and we ship no collector: a defended raid that wins leaves a field the account currently walks away from. The field coordinates and mass come from the host's own battle report (host-read, gate 1), the recycler is chosen by recycle capacity per metal-equivalent cost (no named ship, gate 1), and it is the "send recyclers to the field" move any experienced player names (gate 3) |

## ENHANCE

None. Every mechanism r4fek shares with us is strictly worse: hardcoded cost/energy/transport formulas, a fixed three-mine ladder, all-five-tiers defense spam, 3×100-transporter expeditions per loop, and a fleetsave that always asks for more resources than held. There is no row where copying a refinement would improve a host-read, human-named implementation.

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| — | — | — | — |

## ALREADY-DONE (confirmed by grep)

- Mine/energy/storage upgrade selection, host-read (not a fixed `[metal, metal−2, metal−5]` ladder): `app/Domain/Decision/EconomyUpgrades.php`, `EnergyCapacity.php`, `QueueableBuildingPlanner.php`
- Prerequisite facilities before what they unlock (not hardcoded): `app/Domain/Decision/FacilityChain.php`
- Transport need `max(0, cost − stock)`: `QueueableTransferPlanner.php::need()` (+ in-flight netting, strictly better than M14/M15)
- Donor-skip floor — **already adopted from this repo**: `QueueableTransferPlanner.php` `MINIMUM_SHIPMENT = 50_000` (comment cites "r4fek's documented floor", M16)
- Reserve floor after spend (better than M15's Σ-stock check): `app/Domain/Decision/ReserveFloor.php`
- Transport dispatch via host mission + host cargo capacity (not `lt*5000 + dt*25000`): `QueueableTransferPlanner.php`, `app/Actions/QueueAiTransferAction.php`
- Reactive defense on inbound hostile, host `underAttack` trigger, best attack-per-cost hull (not defense id spam 401–406): `QueueableUnitPlanner.php::underAttack()/bestDefense()`, `app/Actions/QueueAiUnitsAction.php`
- Fleetsave: deployment between own planets, ranked destinations, shadow split, deliberate skip (`SaveFailurePolicy`) — far beyond M22/M23: `QueueableFleetSavePlanner.php`, `SaveFailurePolicy.php`, `app/Actions/QueueAiFleetSaveAction.php`, `QueueAiRecallAction.php`
- Attack detection + reaction wake inside the reaction window (host inbound fleet, not `#attack_alert` scraping): `app/Domain/Perception/PlayerObservationService.php`, `app/Domain/Scheduling/SessionDecisionService.php`
- Expedition on slot 16, host-gated slots + disposable cargo ship (not 3×100 `dt` per loop): `QueueableExpeditionPlanner.php`, `app/Actions/QueueAiExpeditionAction.php`
- Farming as a raid pipeline: bashing limit 6, profit test, storage-fill schedule, host battle-engine sampling (not a hardcoded `farms=` coord list): `app/Domain/Decision/RaidPlanner.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php`, `QueueAiRaidAction.php`
- Planet/resource/fleet/ship discovery via host `PlayerService`/`PlanetService`/`ObjectService` (no HTML scraping, M03–M06): `QueueableBuildingPlanner.php` refresh block
- Building/research/unit/colony/spy/recall planners (M07/M08 equivalents): `app/Domain/Decision/Queueable*.php`, `app/Actions/QueueAi*.php`
- Human cadence — waking-day window, Weibull waits, absences, reaction wake (not `randint+400s` loop or 60s attack poll): `app/Domain/Routine/SessionPlanner.php`, `app/Domain/Scheduling/NextDueTimeCalculator.php`
- Distance / server time / inactivity flags: read from the host, never reimplemented (M02/M29/M30)

## REFUSE

- Login, page/HTML scraping, form-id constants, 12-year-old UA (M01/M05/M06/M32) — obsolete browser bot; we run host-side.
- Building/energy/transport cost formulas `FIRST_COST`/`FACTORS`/`ENERGY_COST_FACTORS` (M10/M11/M12) — hardcoded universe; host `ObjectService` computes.
- Fixed three-mine ladder `[metal, metal−2, metal−5]` (M08) — hardcoded object list, gate 1.
- Defense spam all five tiers to 100 (M18) — hardcoded ids 401–406 + machine play, gate 3.
- Danger rule `detailsFleet > max_ships` (M20) — hardcoded threshold over scraped fleet count.
- Fleetsave at 10% with `resources+500` each (M22) — asks for more than held, machine-shaped; ours is a real save.
- 3 expeditions of 100 `dt` every cycle, two `farm()` calls per cycle, 60s attack polling, galaxy scans unthrottled (M25/M26/M31) — machine cadence, gate 3.
- Hardcoded `farms=` coordinates, `expedition planets`, defense ids, `ships_kind` (config.ini) — hardcoded universe, gate 1.
- Inactive scanner (M28) — dead code path + hardcoded rank window [900, 4000] + browser scraping; farming targets already come from host intel via the raid pipeline.
- Debris collect as written (M24) — "recyclers to own coords, target `debris`" dead code; the *idea* is adopted above, never the code.
- `collect_debris`/`find_inactives`/`find_inactive_nearby` dead paths, broken `Options.valid` (M24/M28) — dead/broken code.
- Config hot-reload via watchdog (M33) — Laravel config cache; YAGNI, gate 2.
- SMS via `smsapi.pl` with hardcoded credentials (M34) — out of scope, host-side notification, secret in repo.
- supervisord PID-file daemon (M35) — host-side daemon forbidden; Horizon + queue workers already supervise.
- Message-the-attacker taunt (M21) — auto-taunting is bot-identifiable and our social layer already exists.

## Priority recommendation

Ship **debris collection** first, as the only genuinely new capability. One planner (`QueueableDebrisCollectPlanner`) reads the freshest observed battle report the account was a combatant in, asks the host for the field's coordinates and metal-equivalent mass, and refuses below a minimum worth the recycler trip; the recycler hull is the best recycle-capacity-per-cost unit the planet can queue. It is bounded to one pass, no new dependency, and the host remains the authority on legality and affordability.

## Open questions / risks

- Host surface: confirm a battle report exposes the debris field (coordinates + mass) to the defender; if not, harvest has no host-read signal and should be cut, not faked.
- Recycler role: verify the host unit catalogue carries recycle capacity as a sortable property (as cargo/attack are for `QueueableUnitPlanner`); if only a hardcoded ship can recycle, the role must still be read from host data, never named.
- Minimum-mass threshold is a persona-flavour constant (like `RAID_STORAGE_FILL_RATIO`), not a hardcoded universe fact — document the source band in the plan.
- No other mechanism in this repo is worth adopting: a second pass will find only more gate-1/gate-3 violations.
