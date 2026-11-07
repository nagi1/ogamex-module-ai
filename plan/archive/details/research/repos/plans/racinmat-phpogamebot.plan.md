# Enhancement plan — racinmat/PHPOgameBot

## Verdict summary
The repo is a 10-year-old browser-scraping bot whose only transferable value is two host-readable *ideas* we lack (grow storage before a build that won't fit, and escalate probe count to crack a partial report); everything else is already shipped better or refused.

## ADOPT-IDEA

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Storage-before-build preprocessor | When a build's price exceeds the planet's current storage capacity, prepend the minimal storage upgrades for the blocking resource(s), sorted by price, before queueing the build | `EconomyUpgrades.php` (new pass `storageForPrice()`) + `QueueableBuildingPlanner.php` | A young planet's next mine can cost more than its warehouse holds; the host rejects it and the planner silently falls through, so the account stalls. A player upgrades the warehouse first. Derive "price exceeds storage" from `ObjectService::getObjectPrice()` vs `PlanetService::*Storage()` |

## ENHANCE

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `QueueAiSpyAction` always sends exactly **one** probe | Recomputes probe count from the last espionage result and escalates until the report is complete | Read the target's most recent `EspionageReport` completeness, send $N$ probes / re-probe a partial target, bounded (1–5 probes, defended/known-rich targets only) | `QueueableSpyPlanner.php`, `QueueableSpy.php`, `QueueAiSpyAction.php`, `QueueAiSpy` |

## ALREADY-DONE (confirmed by grep)

- Fleetsave (reactive + proactive + deliberate skip, ranked destinations, shadow split): `QueueableFleetSavePlanner.php`, `SaveFailurePolicy.php`, `QueueAiFleetSaveAction.php`, `QueueAiRecallAction.php`
- Free fleet-slot / expedition-slot gating: `PlayerObservationService.php`, `QueueableExpeditionPlanner.php`, `CandidateActionFactory.php`
- Loot capped at cargo capacity, half-loot via host class fraction: `RaidPlanner.php::expectedLoot()`
- Raid policy (bashing limit 6, profit test, loot tier, storage-fill schedule): `RaidPlanner.php`
- Target ranking by known yield and distance: `QueueableSpyPlanner.php::target()`
- Storage about-to-overflow upgrade: `EconomyUpgrades.php::storage()` + `QueueableBuildingPlanner.php`
- Reserve floor after spend: `ReserveFloor.php`
- Human cadence (waking-day window, 9h dark, Weibull waits, absences): `SessionPlanner.php`, `NextDueTimeCalculator.php`
- Expedition slot 16: `QueueableExpeditionPlanner.php`
- Queue retry / attempt cap: `ProcessAiWork.php`, `AiWorkState.php`
- Cargo ship sizing for expected loot: `QueueableUnitPlanner.php`
- Probe budget (idle probes minus committed intents): `QueueableSpyPlanner.php::origin()`
- Production/storage/price math — host `PlanetService`/`ObjectService`; never reimplemented

## REFUSE

- Fast GET-URL fleet send — bypasses the UI; machine play.
- Galaxy scan + Czech CSS/id/object-number tables — hardcoded universe + DOM scraping.
- Fixed `sleep(40)` probe wait — mechanical timing.
- Flight-cache TTL and near-arrival parse guard — DOM artifacts.
- DOM micro-jitter sleeps — browser-only.
- Hardcoded production/storage/price formulas — host computes these.
- Fleetsave as written (all fleet → first colony + 100M) — scripted; ours is better.
- Per-command "earliest availability" scheduler — machine precision.

## Priority recommendation

Build the **storage-before-build preprocessor** first. It closes a correctness gap: a young planet whose next mine is priced above its warehouse stops spending and never recovers, because `EconomyUpgrades::storage()` fires only on "about to fill", not on "next price won't fit". One pass over host price vs host storage, prepending the cheapest storage step that makes the target build affordable. Probe escalation is second, once the host report-completeness signal is confirmed.

## Open questions / risks

- Host report completeness: verify `EspionageReport` exposes which sections were redacted; if not, probe escalation has no signal and should be cut.
- Gate 1 on the preprocessor: derive from `ObjectService` price + `PlanetService` storage only; never hardcode a storage object id.
- Probe escalation cost: bounded (cap probes, defended/rich targets only).
- Both changes stay module policy; the host remains the authority on legality/affordability.
