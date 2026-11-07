# Enhancement plan — janeczkins/xbot

## Verdict summary (one line)

Mine the two real gameplay gaps (debris-field harvesting, fleet jump-gate moves) and two
enhancements to what we already ship (surplus-sweep transfer, colony auto-abandon) as host-read
builds; refuse every anti-detection, battle-sim, product and hardcoded-object mechanism.

## ADOPT-IDEA — table

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| AutoHarvest (M32) | Send recyclers to own/deep-space debris fields after a battle, gated by a minimum-field threshold (own ≥ ~300, deep-space ≥ ~50k) | New `app/Domain/Decision/QueueableHarvest` + `QueueableHarvestPlanner`; dispatch via host `RecycleMission` (`app/GameMissions/RecycleMission.php`); field read via host `DebrisFieldService` (`app/Services/DebrisFieldService.php`) + `app/Models/DebrisField.php` | Closes a genuine gap — nothing in the module ever collects debris. A professional player recycles the field left by a defended raid (`RaidPlanner` already prices debris into defended raids) and their own moon-destruction leftovers. Host-read, gate-3 nameable. |
| AutoFleetJumpGate (M28) | Move fleet between the account's own moons through a jump gate when the host reports one and a minimum move size is met | New `QueueableJumpGateTransfer` + planner beside `QueueableTransferPlanner`; seam depends on a host jump-gate dispatch (verify before building) | A late-game fleeter uses a gate to reposition between moons; nameable and host-read, but low priority — only worth it once moons/gates exist in the target cohort. |

## ENHANCE — table

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `QueueableTransferPlanner::plan()` is need-driven only: it funds a colony's next build from a surplus source, netting in-flight transports and keeping a `ReserveFloor` | AutoRepatriate/MultipleOrigins sweep the opposite direction — collect everything above a floor from outlying colonies to one drop body, keep deuterium on moons for the fleet, skip in-flight targets (`SkipIfIncomingTransport`) | Add a surplus-sweep branch: above-floor pull to a designated body, with a moon-deuterium floor reusing `ReserveFloor` (we already net in-flight, so only the direction and the moon-deut floor are new) | `app/Domain/Decision/QueueableTransferPlanner.php`, `app/Domain/Decision/ReserveFloor.php` |
| `QueueableColonyPlanner` finds an empty slot and colonises; it never abandons a bad colony | AutoColonize Abandon (M34): abandon + recolonise when fields/temperature fall outside a band (`MinFields` 280, temp −130…260 °C) | Add a host-read abandon gate — planet field count and temperature come from the host planet service — so a below-band colony is abandoned and re-queued | `app/Domain/Decision/QueueableColonyPlanner.php` (new `QueueableColonyAbandon` + host abandon action) |
| `QueueableFleetSavePlanner::plan()` saves whenever a hostile is inbound, whatever the fleet value | Defender value gate (`MinFleetToSave` 20M / `MinResourcesToSave` 2M) and `IgnoreWeakAttack`/`WeakAttackRatio` skip trivial or over-matched saves | Skip the reactive save when the threatened fleet is below the persona's exposure band — reuse the exact band `proactivePlan()` already applies, so no new constant | `app/Domain/Decision/QueueableFleetSavePlanner.php`, `app/Domain/Decision/SaveFailurePolicy.php` |

## ALREADY-DONE (confirmed by grep) — list with file path

- Reactive + proactive fleetsave, deploy-recall: `app/Domain/Decision/QueueableFleetSavePlanner.php` (`plan()` / `proactivePlan()`), `app/Domain/Decision/QueueableRecall.php`, `app/Actions/QueueAiFleetSaveAction.php`, `app/Actions/QueueAiRecallAction.php`
- Fleet save that can also fail (gate-3 counterpart to "always save"): `app/Domain/Decision/SaveFailurePolicy.php`
- Transfer/transport, netted against in-flight + reserve floor: `app/Domain/Decision/QueueableTransferPlanner.php`, `app/Actions/QueueAiTransferAction.php`
- Colonise (bounded empty-slot scan, host `canColonizePosition`): `app/Domain/Decision/QueueableColonyPlanner.php`, `app/Actions/QueueAiColonyAction.php`
- Expedition (slot-16, one disposable civil ship): `app/Domain/Decision/QueueableExpeditionPlanner.php`, `app/Actions/QueueAiExpeditionAction.php`
- Spy (bounded neighbour probing, fresh-intel + in-flight skip): `app/Domain/Decision/QueueableSpyPlanner.php`, `app/Actions/QueueAiSpyAction.php`
- Raid (bashing limit, loot-to-fuel ratio, profit test, storage-fill schedule): `app/Domain/Decision/RaidPlanner.php`, `app/Actions/QueueAiRaidAction.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php` (samples host `PhpBattleEngine`, not a second engine)
- Mine ROI with payback horizon + absence-sized storage: `app/Domain/Decision/EconomyUpgrades.php`, `app/Domain/Decision/EnergyCapacity.php`
- Research (host-derived prerequisites, mission-required, fleet-slot-ceiling): `app/Domain/Decision/FacilityChain.php`
- Incoming-attack detection (Defender polling equivalent): `app/Domain/Perception/PlayerObservationService.php::inboundThreat()` (host `currentPlayerUnderAttack()`)
- Nightly/absence cadence (SleepMode window equivalent): `app/Domain/Routine/SessionPlanner.php`, `app/Domain/Routine/RoutineProfile.php`, proactive save via `app/Domain/Decision/CandidateActionFactory.php`
- Reactive defence purchase under attack (AutoDefence equivalent): `app/Domain/Decision/QueueableUnitPlanner.php`

## REFUSE — list with one-line reason

- `DeviceConf` fingerprint, proxy, captcha, `RandomActivity`, random jitter "to look human" — bot-masking is the inverse of our gate-3 authenticity goal, and browser/API scraping is banned by policy.
- FleetAnalyzer battle simulator + `AttackerFleet`/`AttackerTechs` config — a second battle engine duplicating host combat math; we already sample `PhpBattleEngine` read-only.
- WebUI / Telegram / Discord / stats charts / license-trial / multi-instance — product and host-side daemon machinery, not gameplay.
- Hardcoded object tables (`MaxMetalMine` 40, tech caps, 15 ship strings, defence types) — gate-1 violation; we read `ObjectService` and never name objects.
- LifeformAutoMine / LifeformAutoResearch / AutoDiscovery — large niche config surface (gate-2) and host lifeform support unverified.
- MessageAttacker auto-DM pool — machine-shaped; our social protocol (`ClassifyInboundSocialExchangeAction`) already answers inbound messages human-shaped.
- `SlotsToLeaveFree` + `SlotPriorityLevel` arbitration — gate-2; `UtilityScorer` plus host fleet-slot checks already arbitrate.
- BuyOfferOfTheDay — host `MerchantService` is a 3500-DM resource trade with no daily-item feed; auto-spending dark matter is not named ordinary play.
- Defender `SpyAttacker` (auto-probe the attacker) — covered by `QueueableSpyPlanner` neighbour probing; reactive attacker-probing is marginal.

## Priority recommendation — single highest-value change

Build **AutoHarvest** first. It is the only missing capability that is both a full feature gap (no
code anywhere collects debris) and immediately nameable as ordinary play — a player sends recyclers
to the field left by a defended raid, which `RaidPlanner` already prices in. The host seam is fully
present (`DebrisFieldService`, `DebrisField`, `RecycleMission`), so the work is one planner + one
dispatch adapter, no new dependency, fully host-read. Everything else above is a variation on a
mechanism we already ship.

## Open questions / risks

- **Jump-gate and abandon seams unverified:** AutoFleetJumpGate and AutoColonize Abandon need a host dispatch/abandon path I did not confirm; do not build them until the host seam exists.
- **Binary-only algorithms:** XBot's ROI, combat and fleet-composition formulas are documented behaviour, not source; thresholds may drift from the shipped binary, so we adopt ideas and derive every constant from host data, never a copied number.
- **Harvest executor scope:** the plan must dispatch recyclers without growing a fleet-composition solver — one recycler type read from the host, one field per decision, like `QueueableExpeditionPlanner::disposableShip()`.
