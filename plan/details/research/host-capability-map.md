# What the host already answers, and what it does not

Read-only survey of the OGameX host, 14 September 2026, taken because the
[gameplay algorithms](../specs/gameplay-algorithms.md) presume host answers and guessing them from
names is the mistake this file exists to prevent. Every row was verified in source; anything that could
not be confirmed is marked **not found** rather than inferred. Exact methods, not descriptions: a gap is
closed against a call, not against an idea.

Scope note: `Modules/` contains only `AI/` and `HelloWorld/`. Alliances, chat, notes and buddies are
plain host `app/` code. The host's module system is `app/Modules/`, with a closed slot list.

## A. Queues and production

| Capability | Exists | Exact call | Note |
| --- | --- | --- | --- |
| Research enqueue | yes | `ResearchQueueService::add(PlayerService, PlanetService, int): void` | throws on queue-full, wrong type or unmet requirements; **does not check affordability** — cost is deducted at `start()` and the entry is cancelled if it fails, so a caller must pre-check |
| Research queue read | yes | `retrieveQueue(PlanetService)`, `retrieveQueueForPlanet(PlanetService)` | player-scoped and planet-scoped |
| Research completion | yes | `PlayerService::updateResearchQueue(bool $save_user = true)` | sets the level, fires `ResearchCompleted` |
| Unit enqueue | yes | `UnitQueueService::add(PlanetService, int $object_id, int $requested_build_amount): void` | clamps to `getObjectMaxBuildAmount`; **silently returns** when unaffordable or class-blocked, so "added 0" is indistinguishable from success |
| Unit queue read | yes | `retrieveQueue(PlanetService)`, `retrieveBuilding(int)`, `retrieveQueueTimeEnd(PlanetService)` | |
| Unit requirements | yes | `ObjectService::objectRequirementsMet()`, `objectCharacterClassMet()` | from the object definition's own requirement graph, not a constant |
| Unit properties | yes | `UnitObject::$properties` (`capacity`, `fuel_capacity`, `fuel`, `speed`, `structural_integrity`, `shield`, `attack`), each with `rawValue` and `calculate($player)->totalValue` | the source of role derivation |
| Aggregate capacity and speed | yes | `UnitCollection::getTotalCargoCapacity/getTotalFuelCapacity/getSlowestUnitSpeed(PlayerService): int` | |
| Building enqueue | yes | `BuildingQueueService::add(PlanetService, int)`, `addDowngrade(PlanetService, int)` | |
| Building processing and cancellation | yes | `PlanetService::updateBuildingQueue(bool)`; `BuildingQueueService::start()` / `cancel()` | cancels on target-level mismatch, requirements, resources, lab-while-researching, downgrade with dependents, missile silo loaded, shipyard busy |
| Storage capacity read | yes | `PlanetService::metalStorage()/crystalStorage()/deuteriumStorage(): Resource` | stored `*_max` columns |
| Storage objects | **partly** | `ObjectService::getBuildingObjectsWithStorage(): array<BuildingObject>` | **excludes stations**, so a mod-added station with storage is invisible to the enumeration — recorded as a host obligation |
| Storage computation | yes | `PlanetService::updateResourceStorageStats(bool)`, `getBuildingMaxStorage(string, int\|bool)` | formulas are `eval`'d strings: call them, never copy them |
| Energy balance | yes | `PlanetService::energy()`, `energyProduction()`, `energyConsumption(): Resource` | stored `energy_max` / `energy_used` columns |
| Production-efficiency factor | yes | `PlanetService::getResourceProductionFactor(): int` | `floor(production/consumption*100)` clamped 0–100, from the stored columns |
| Refresh in memory | yes | `PlanetService::update()`, or narrowly `updateResourceProductionStats(bool $save_planet = true)` | there is no "recompute without saving" entry except passing `false` |
| Which objects produce energy | yes, by convention | `ObjectService::getGameObjectsWithProduction()`, sign of `ProductionIndex->total->energy` | "producer" is a sign convention, not a flag or a type |
| Raw production per level | yes | `PlanetService::getObjectProduction(string, int\|null, bool $force_factor = false)`, `getObjectProductionIndex(...)` | `force_factor = true` is the key to raw, unscaled output |
| Build time per level | yes | `getBuildingConstructionTime(string)`, `getUnitConstructionTime(string)`, `getTechnologyResearchTime(string)`, `getBuildingDowngradeTime(string, int\|null)` | always "next level" from current state; a future level needs `getObjectRawPrice()` fed to your own formula |

## B. Fleets and missions

| Capability | Exists | Exact call | Note |
| --- | --- | --- | --- |
| Fleet dispatch | yes | `FleetMissionService::createNewFromPlanet(PlanetService, Coordinate, PlanetType, int $missionType, UnitCollection, Resources, float $speedPercent, int $holdingHours = 0, int $parent_id = 0, bool $retreatAfterDefenderRetreat = false): FleetMission` → `GameMission::start()` | |
| Mission catalogue | yes, **no enum** | `GameMissionFactory::getAllMissions()`, `getMissionById(int)` | 1 attack, 2 ACS attack, 3 transport, 4 deployment, 5 ACS defend, 6 espionage, 7 colonisation, 8 recycle, 9 moon destruction, 10 missile, 15 expedition; per-class `getTypeId/getName/hasReturnMission/getFleetSpeedType/getFriendlyStatus/isBlockedByServerAttackBlock`. Read the catalogue — an enum in module code would be gate 1 |
| Fuel | yes | `FleetMissionService::calculateConsumption(PlanetService, UnitCollection, Coordinate, int $holdingHours, float $speedPercent): int` | **per ship entry**, base `fuel->rawValue × amount × distance/35000 × (shipSpeedValue/10+1)²`, plus holding, then class and universe multipliers |
| Distance and duration | yes | `calculateFleetMissionDistance()`, `calculateFleetMissionDuration(...)` | duration honours the mission's `FleetSpeedType` |
| Slots | yes | `PlayerService::getFleetSlotsInUse/getFleetSlotsMax/getExpeditionSlotsInUse/getExpeditionSlotsMax(): int` | enforced in `startMissionSanityChecks()` by throw |
| Cargo and fuel capacity | yes | `UnitCollection::getTotalCargoCapacity()`, `getTotalFuelCapacity()` | enforced in `GameMission::start()` |
| Deployment | yes | `DeploymentMission` (type 4) | own planet only; **self-relocation is not recallable** (`cancelMission` returns early) |
| Transport | yes | `TransportMission` (type 3) | accepts own planets — the transport path serves intra-empire ferrying |
| Recycle | yes | `RecycleMission` (type 8) | needs a recycler (positions 1–15) or pathfinder (16); debris target type required |
| Colonise | yes | `ColonisationMission` (type 7), `hasReturnMission = false` | |
| Espionage | yes | `EspionageMission` (type 6) | visibility thresholds in `canRevealData`: ships 2/1, defence 3/2, buildings 5/3, research 7/4 |
| Recall | yes, **ownership unchecked** | `FleetMissionService::cancelMission(FleetMission)` → `GameMission::cancel()` + `startReturn()` | the owner comparison lives in the controller — the module must re-do it |
| Scheduled arrivals | yes | `syncMissionArrivalJobs` / `cancelMissionArrivalJobs` / `arrivalQueueForMission` + `ProcessFleetArrival`, columns `arrival_job_id`, `hold_job_id`, `time_arrival`, `time_arrival_ms`, `time_holding` | lanes `QueueName::FleetArrivals` / `FleetArrivalsHeavy` |
| Inbound-fleet intel | **no** | `IncomingFleetIntelService::resolveLevel(PlayerService)`, `apply(...)`, `applyFriendly(...)`, `applyToUnionSummary(...)` | it **redacts a row you already built**; it reports no origin, ETA or composition. The data must be assembled from `getActiveFleetMissionsForCurrentPlayer()` + `getFleetUnits(FleetMission)` + `getFleetUnitCount()`, as `FleetController::movement()` does |
| Espionage report model | yes | `OGame\Models\EspionageReport`, table `espionage_reports` | planet coordinates and type, target player, `resources`, `debris`, `buildings`, `research`, `ships`, `defense`, `counter_espionage_chance`; **no `expires_at` and no prune** |
| Colony creation | yes | `PlanetServiceFactory::createAdditionalPlanetForPlayer(PlayerService, Coordinate): PlanetService` | fires `PlanetCreated` |
| Slot legality and count | yes | `PlayerService::canColonizePosition(int)`, `getMaxPlanetAmount()`, `UniverseConstants::MIN/MAX_PLANET_POSITION` | 1/15 needs astrophysics 8, 2/14 → 6, 3/13 → 4 |
| Position yield bonuses | yes | `PlanetService::getProductionForPositionBonuses(int): array{metal,crystal,deuterium}` | the honest input for colony slot choice |
| Fields and temperature | partly | `getPlanetFieldMax()`, `getPlanetTempMin/Max/Avg()` | written only by a **private** `setupPlanetProperties()`, so a pre-flight judgement has no public lookup |
| Debris fields | yes | `DebrisFieldService::loadOrCreateForCoordinates/loadForCoordinates/getResources/appendResources/deductResources/calculateRequiredRecyclers()`, model `OGame\Models\DebrisField` | weekly `ogamex:scheduler:reset-debris-fields` |
| Wreck fields | yes | `OGame\Models\WreckField`, `WreckFieldService`, Space Dock repair, `wreck_fields.expires_at` | a separate mechanic from debris |
| Battle engine | yes | `GameMissions\BattleEngine\{BattleEngine, PhpBattleEngine, RustBattleEngine}`; `new BattleEngine(array<AttackerFleet>, PlanetService, array<DefenderFleet>, SettingsService)`; `->simulateBattle(): BattleResult` | selected by `SettingsService::battleEngine()` |
| Battle determinism | **no** | `UnitObject::didSuccessfulRapidfire()` uses `random_int`; `damagedHullExplosion()`, `checkHamillManoeuvre()`, `rollMoonCreation()` | no seed parameter anywhere; the Rust engine has its own RNG |
| Read-only simulation | **no entry point** | `BattleEngine::applyTacticalRetreat()` writes resources; `simulateBattle()` fires `BattleResolved` | `simulateBattle()` is mostly in-memory but has side effects; the nearest precedent is `EspionageMission::executeCounterEspionageBattle()`, which removes defence with `save = false` and restores it |
| Marketplace / trade | **none** | — | the only exchange is the Dark-Matter merchant: `MerchantService::callMerchant(PlayerService, string): array`, `DARK_MATTER_COST = 3500`, `generateTradeRates(string)`, model `MerchantCall` |

## C. Player and session state

| Capability | Exists | Exact call | Note |
| --- | --- | --- | --- |
| Ban / vacation | yes | `PlayerService::isBanned()`, `isInVacationMode()`, `User::currentBan()`, `bans` table | only `QueueAiBuildingAction` asks today; the session path does not |
| Last activity | yes, **already written by the module** | `PlayerService::update()` sets `users.time` and `last_ip` from the ambient request; reached through `PlayerGameStateService::advance(int $playerId, int\|null $currentPlanetId = null): PlayerService` | `advance()` is the documented seam for scheduled actors and the module already calls it — so register A5's "nothing touches `users.time`" is false transitively, and `last_ip` gets the queue context's address |
| Activity marker | yes | `GalaxyController::getPlanetActivityStatus(PlanetService): array{idleTime,showActivity,showMinutes}` | driven by the **planet's** `time_last_update`: under 15 min shows 15, 15–60 shows minutes, over 60 shows nothing; any planet-context request refreshes it, including someone else's probe |
| Inactive flags | yes | `PlayerService::isInactive()` (≥7 days), `isLongInactive()` (≥28 days), `isNewbie(PlayerService)`, `isStrong(PlayerService)` | computed from `users.time` |
| Inactive deletion | yes | `ogamex:scheduler:delete-inactive-players`, `SettingsService::inactivePlayerDeletionDays()` | 0 disables; excludes staff |
| Alliance | yes | `OGame\Models\{Alliance,AllianceMember,AllianceRank,AllianceApplication,AllianceHighscore}`, `AllianceService` | |
| Alliance chat | yes | `ChatService::sendAllianceMessage(int, int, string, int\|null = null)`, `getAllianceMessages(int, int = 50, int\|null = null)` | the module's chat observer returns early when `alliance_id` is set |
| Direct chat | yes | `ChatService::sendDirectMessage(...)`, `canMessagePlayer(int, int)`, `getConversation(...)` | permission is only "not ignored"; length, self-send and existence are controller-side and are re-implemented in `DeliverAiDirectReplyAction` |
| Buddy requests | yes | `BuddyService::{getBuddies,areBuddies,sendRequest,getReceivedRequests,getSentRequests,getUnreadRequestsCount}`, `BuddyRequest`, `IgnoredPlayer` | nothing observes or answers them |
| Player notes | yes | `NoteService::{createNoteForUser,getAllNotesForUser,getNoteById,updateNoteForUser,deleteAllNotesForUser,deleteMarkedNotes}`, `Note` | nothing observes them |
| Observers the module uses | yes | `ChatMessage`, `AllianceMember`, `BattleReport` observers, `BuildingCompleted` listener | no fleet observer, which is gap G8 |
| Highscore points | yes, **no history** | `highscores` (`general, economy, research, military_built, military_destroyed, military_lost` plus ranks), `HighscoreService::{getPlayerScore,getPlayerScoreMilitary,getPlayerScoreEconomy,getPlayerScoreResearch,getPlayerTotalShipCount}`, commands `ogamex:scheduler:generate-highscores`, `…:generate-highscore-ranks`, `PlayerService::getCachedGeneralScore()` | **no time series**, so the "public growth curve" must be recorded by the module |
| Host's own bot detector | yes | `app/Http/Controllers/Admin/ServerAdministrationController.php`, defaults at 282–289 | signal 1: `bot_detection_lookback_days = 7`, ≥18 distinct hours-of-day with a mission departure, ≥18 missions per fleet slot per day, 50-mission floor, probes and missiles excluded; signal 2: an expedition re-dispatched ≤10 s after landing, ≥5 times; signal 3: a fleet leaving ≤10 s after an attack departs, ≥1 time; plus shared-IP grouping of 2–10 users |

## D. Module extension points

| Point | Exists | Exact path |
| --- | --- | --- |
| Install / uninstall hooks | yes | `app/Modules/ModuleHooks.php`, `Contracts/ModuleHook.php`, `ModuleHookContext.php`; convention `Modules\<Name>\Hooks\{InstallModule,UninstallModule}` |
| Admin view slots | yes, **closed list** | `ModuleSlotService::register/render/hasSlot`, `SLOTS = ['admin.nav']` — any other name throws |
| Host game events | yes | `app/Events/Game/`: `BuildingCompleted`, `ResearchCompleted`, `FleetMissionArrived`, `BattleResolved`, `PlanetCreated`, `PlayerCreated` |
| Model observers | yes | any host model; the module attaches to `ChatMessage`, `AllianceMember`, `BattleReport` |
| Scheduled-actor seam | yes | `OGame\Services\PlayerGameStateService::advance(int, int\|null)` — documented as the way a scheduled actor runs due queue and fleet processing without faking an HTTP request |
| Scheduler | yes | `AIServiceProvider::configureSchedules(Schedule)`, module `HorizonServiceProvider`, `ai:run-due-work` every minute |
| Universe settings | yes | `SettingsService`: `numberOfGalaxies/Systems`, `economySpeed`, `researchSpeed`, `fleetSpeed*`, `deuteriumConsumption`, `battleEngine`, `attackBlockActive`, `missionBlockedByAttackType`, `inactivePlayerDeletionDays`, `wreckFieldLifetimeHours`, `allianceCombatSystemOn` |
| Game messages | yes | `app/GameMessages/` + `GameMessageFactory`; `MessageService::sendSystemMessageToPlayer`, `sendEspionageReportMessageToPlayer` |
| Where a host change *is* expected | — | a new `QueueName` lane, a new entry in `ModuleSlotService::SLOTS`, a new `app/Events/Game/*` event, or a new scheduler command. A module cannot widen the closed slot list or add a mission type |

## Host obligations

Ten rules the module currently has to restate, work around, or cannot obey. Each is a host change, not a
module edit — and a host obligation is closed by the host change, with the module re-checked against the
merged revision.

1. **The queue-upgrade predicate (A3, B2, C3).** "A shipyard or nanite factory may not be upgraded while
   units are building" exists only in `AbstractBuildingsController::addBuildRequest()`, keyed on the raw
   ids `21` and `15`. `PlayerService::isBuildingShipsOrDefense()` exists but does not say *which* object
   is blocked. Until a service publishes the pair, the module's `AiBuildingMachineName` stays — deleting
   it would let the account take a fleet-slot queue entry for an upgrade the game then cancels.
2. **Vacation mode blocks queue additions in controllers only.** `AbstractBuildingsController`,
   `AbstractUnitsController` and `ResearchController` each refuse on `isInVacationMode()`; the services
   check it only while *processing*. A module that queues directly can therefore add work that will never
   run — already noted in O2's neighbourhood, and it applies to all three queues.
3. **Fleet-recall ownership is controller-only.** `FleetMissionService::cancelMission()` performs no
   owner comparison.
4. **The attack-block response is controller-only.** Only the per-mission `static
   $blockedByServerAttackBlock` reaches the service; `SettingsService::missionBlockedByAttackBlock()` is
   called by `FleetController::dispatchSendFleet()`.
5. **Expedition holding-hours bounds are controller-only** (`holding_hours` between 1 and the
   astrophysics level).
6. **The fleet-speed whitelist is controller-only** (1.0–10.0 in 0.5 steps, plus 0.5 for one class);
   `GameMission::start()` accepts any float.
7. **Coordinate bounds are not enforced in the service** (`FleetController::validateCoordinates`).
8. **Chat permission is only "not ignored"** — recipient existence, self-send, non-blank and the
   2,000-character limit live in `ChatController` and are re-implemented in the module.
9. **Nothing prunes `espionage_reports`.** The only retention is `delete-old-messages` (7 days) for
   inbox `messages` and `chat_messages`. Reports grow forever, which affects gap O1's sizing.
10. **`getBuildingObjectsWithStorage()` excludes stations**, so storage enumeration is blind to any
    station a mod adds — a gate-1 hole in the host's own catalogue, not in the module.

## What this changes

- **G8's premise is corrected.** The register says "the host already has `IncomingFleetIntelService`, so
  the data exists". The service is a redactor. The data exists, but it must be assembled, and the
  assembly is named in [the saving algorithm](../specs/gameplay-algorithms.md#v2-the-reaction-window).
- **Register A5 is corrected.** `PlayerGameStateService::advance()` already stamps `users.time` and
  `last_ip`, and the module calls it from the building action — so the stamp is a decision to make, not
  an absence to fill.
- **The estimator's constraint is now explicit.** There is no read-only battle entry point and no seed,
  so the raid estimator ([T2](../specs/gameplay-algorithms.md#t2-the-estimator)) either gets a host
  change or lives with `simulateBattle()`'s side effects and non-determinism — which is why a
  byte-stable replay test depends on that host change.
- **A hard authenticity ceiling is available for free.** The host's own detector (C, last row) is the
  acceptance test for the routine slice, and its thresholds are the numbers G10 and G11 must satisfy.
- **Two host obligations are new and cheap:** the storage enumeration gap (10) and the vacation-mode on
  add (2); both are one-line changes with a large effect on what the module can promise.
