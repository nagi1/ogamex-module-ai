# Current system audit: the decision pipeline as it is on 4 October 2026

Read from `nagi1/ogamex-module-ai` at `1440811` and `nagi1/ogamex-next` at `a206575`. Everything marked
**[code]** was read in the source, **[measured]** was run in this investigation (see
[benchmark-plan.md](benchmark-plan.md), section "Measurements already taken"), **[handoff]** is quoted from
`HANDOFF.md` and was not re-run here.

## 1. The short version

- The concepts you listed all exist, but the **one-errand `DecisionEngine` is no longer where most gameplay is
  decided.** Since the architecture migration of 3–4 October (`docs/architecture-diagnosis.md`, `HANDOFF.md`
  "ARCHITECTURE MIGRATION"), a login runs the engine's single errand and then a fixed set of
  rule-based managers that emit many orders each login. [code]
- The engine's candidates are mostly **categories without parameters**. `Build`, `Research`, `QueueUnits`,
  `Expedition`, `Transfer`, `FleetSave`... carry `parameters: []`; the concrete object, target, amount and
  origin are chosen later by a `Queueable*Planner`. Only `Raid` carries a `report_id`.
  (`app/Domain/Decision/CandidateActionFactory.php:66-73`, `:150-157`, `:331-337`) [code]
- The **features are constants per action type** (`CandidateActionFactory::features`, `:452-488`). A model
  trained on today's `ai_decision_traces` would learn to imitate a lookup table. [code]
- The richest, genuinely variable decisions sit in the planners, and the best-shaped one is
  `QueueableBuildingPlanner::passes()`: ordered lists of host-derived `BuildCandidate`s per planet, from which
  the deterministic policy picks "the first one the host would accept". That is a ready-made candidate set
  and a ready-made imitation target. [code]
- Every executable intent ends in a normal host service (`BuildingQueueService::add`,
  `ResearchQueueService::add`, `UnitQueueService::add`, `FleetMissionService::createNewFromPlanet`, ...). No
  module code deducts resources or creates queue rows directly. [code]
- A **virtual-clock, discrete-event simulator already exists** (`ai:sim`,
  `app/Console/Commands/SimulateAiTime.php`, `app/Support/SimulatedTime.php`) and runs the real code against a
  real MySQL copy. Measured here at ×735–×1,005 real time with native cognition. [measured]

## 2. Component inventory

| Your name | Exists? | File | What it actually does | Maturity |
| --- | --- | --- | --- | --- |
| `PerceptionSnapshot` | yes | `app/Domain/Perception/PerceptionSnapshot.php` | Readonly DTO: per-planet metal/crystal/deuterium only, espionage-report projections (confidence, travel cost, activity, permitted, viable, defenceless), `availableActions` booleans, inbound fleets, `fleetsaveEligible`, `recallEligible`, `colonizeEligible`, `fleetSlotsFree`, `recoveryFactor`, upcoming absence. **No buildings, research, fleet, defence, production, queues, score.** | Stable, deliberately thin. Not a usable RL observation on its own. |
| `PlayerPerceptionBuilder` / `PlayerObservationService` | yes | `app/Domain/Perception/*` | Whitelist adapter over host state. 57 ms and 82 queries per build on a day-3 account [measured]. | Works; expensive. |
| `CandidateActionFactory` | yes | `app/Domain/Decision/CandidateActionFactory.php` | Emits `DoNothing` + one candidate per published capability (`AiCapability`) + one each for save/recall/expedition/transfer/defend/trade/relocate/jump gate/missile/recycle/phalanx when the matching planner returns a plan + one `Raid` per report the `RaidPlanner` accepts. Planners are called here just to decide eligibility. | Mature as a gate; features are constants. |
| `UtilityScorer` | yes | `app/Domain/Decision/UtilityScorer.php` | `Σ feature × fixed weight` (30/50/30/25/20/25) + archetype preference ×25 + seeded jitter + optional affect term. `select()` picks uniformly among candidates within the skill band's margin of the best. | Simple; most of the "utility" is per-type constants. |
| `DecisionEngine` | yes | `app/Domain/Decision/DecisionEngine.php` | Factory → scorer → `situationalErrand` (skip Build/Research if a non-chore beats `DoNothing`) → `idleOverride` (seeded chance of `DoNothing`). Returns one `DecisionTrace`. | Stable; now only the login's first errand. |
| `DecisionTrace` | yes | `app/Domain/Decision/DecisionTrace.php`, table `ai_decision_traces` | Selected type + reason, all scored candidates with components, rejections, source timestamps, SHA-256 of the perception, 30-day retention. | Records the *category* choice only. |
| `ArchetypePolicy` | yes | `app/Domain/Decision/Policies/*` | `allows(type)` and `preference(type)` per archetype, read from `resources/behavior/archetype-preferences.yaml`. Bound archetypes: Miner, Raider, Turtle, Fleeter, Hybrid. The YAML also has `trader` and `casual` blocks that no registered policy uses. | Stable. |
| `SessionDecisionService` | yes | `app/Domain/Scheduling/SessionDecisionService.php` | Plans the session window, builds perception, runs the engine, records the trace, schedules exactly one successor session (routine, reaction wake, material-event wake, affordability wake with the PACE-001 floor). | Mature. |
| `ScheduleAiIntentAction` | yes (1,001 lines) | `app/Actions/ScheduleAiIntentAction.php` | Turns the errand into `ai_work_items`, **plus, whatever was chosen**: goal hold (`GoalBoard`), wall orders for bare planets, threat responses, refill of every build queue and the lab, the capital-fleet order, then `runManagers()` (raid waves, missiles, probe batch, transfer, expedition, colony, recycle, `RaidWave` continuation). | This is where most behaviour lives now. |
| `ExecuteAiIntentAction` | yes | `app/Actions/ExecuteAiIntentAction.php` | Per `AiWorkKind`, rebuilds or re-validates the plan (often calling the planner a third time) and calls the matching `QueueAi*` contract. | Mature. |
| `QueueAi*` adapters | 18 contracts | `app/Contracts/QueueAi*.php` → `app/Actions/QueueAi*Action.php` | Each calls `PlayerGameStateService::advance()` and then a host service. Returns `AiActionResult::queued/rejected`. | Mature, host-authoritative. |
| `ProcessAiWork` | yes | `app/Jobs/ProcessAiWork.php` | Lease with token, `Cache::lock('ai:player:{id}')`, admission check, then session or intent. | Mature. |
| `GamePhaseMachine`, `GoalBoard`, `IntelBook`, `GalaxyMap`, `LoginReservations`, `FleetSlots`, `ManagerDoctrine`, `ArchetypeDoctrine` | yes (new, 3–4 Oct) | `app/Domain/Login/*`, `app/Domain/Intel/IntelBook.php`, `app/Domain/Galaxy/GalaxyMap.php`, `app/Domain/Doctrine/ArchetypeDoctrine.php` | Phase (early/mid/late) from host state, persisted goals with hysteresis, per-target raid priority counters, threat/opportunity per system from seen reports, per-login ship claims, doctrine YAML per archetype. | Days old; proofs passed, few hours of sim behind them. |

## 3. Which intents are executable, and what executes them

All 20 `AiWorkKind` values have an executor arm in `ExecuteAiIntentAction::execute` (`:129-151`). The
`HANDOFF`-era statement in `plan/README.md` that `spy`, `colonize`, `fleet_save` and `raid` "remain recorded
intents" is **out of date**; they are executed today.

| Work kind | Planner that chooses parameters | Adapter | Host service that executes |
| --- | --- | --- | --- |
| `BuildFirstBuilding`, `RunSession` (build) | `QueueableBuildingPlanner::steps/plan` | `QueueAiBuildingAction` | `BuildingQueueService::add` |
| `QueueResearch` | `QueueableBuildingPlanner` (research passes, doctrine path) | `QueueAiResearchAction` | `ResearchQueueService::add` |
| `QueueUnits` | `QueueableUnitPlanner::plan/capitalFleetOrder/standingDefenceOrders` | `QueueAiUnitsAction` | `UnitQueueService::add` |
| `Colonize` | `QueueableColonyPlanner` | `QueueAiColonyAction` | `FleetMissionService::createNewFromPlanet` (`ColonisationMission`) |
| `Expedition` | `QueueableExpeditionPlanner` | `QueueAiExpeditionAction` | `FleetMissionService` (`ExpeditionMission`) |
| `Transfer` | `QueueableTransferPlanner` | `QueueAiTransferAction` | `FleetMissionService` (`TransportMission`) |
| `FleetSave` | `QueueableFleetSavePlanner` | `QueueAiFleetSaveAction` | `FleetMissionService` (deployment/recycle missions), `JumpGateService` |
| `Recall` | n/a | `QueueAiRecallAction` | `FleetMissionService::cancelMission` |
| `Spy` | `QueueableSpyPlanner` | `QueueAiSpyAction` | `FleetMissionService` (`EspionageMission`) |
| `Raid`, `RaidWave` | `RaidPlanner` (+ `NativeRaidEstimator`, 50 Rust battles per screen) | `QueueAiRaidAction` | `FleetMissionService` (`AttackMission`) |
| `Recycle` | `QueueableRecyclePlanner` | `QueueAiRecycleAction` | `FleetMissionService` (`RecycleMission`) |
| `SetMinePercent` | `QueueableMinePercentPlanner` | `QueueAiMinePercentAction` | `PlanetService::setBuildingPercent` |
| `Phalanx` | `QueueablePhalanxPlanner` | `QueueAiPhalanxAction` | `PhalanxService` |
| `Defend` | `QueueableDefendPlanner` | `QueueAiDefendAction` | `FleetMissionService` (`AcsDefendMission`) |
| `Trade` | `QueueableTradePlanner` | `QueueAiTradeAction` | `MerchantService` |
| `Relocate` | `QueueableRelocationPlanner` | `QueueAiRelocationAction` | `PlanetMoveService` |
| `JumpGate` | `QueueableJumpGatePlanner` | `QueueAiJumpGateAction` | `JumpGateService` |
| `Missile` | `QueueableMissilePlanner` | `QueueAiMissileAction` | `MissileMission` |

The principle "OGameX is authoritative" holds in code: legality is re-checked by the host at dispatch, and a
stale order is refused (`AiActionResult::rejected`). Keep it.

## 4. Where the real choices are made (important for the action space)

```
login (RunAiSessionAction::handle)
 ├─ host advance of every planet                     (PlayerGameStateService::advance + PlanetService::update)
 ├─ conversation cycle                                (social; setting ai_conversation_enabled, default true)
 ├─ alliance life, first session each minute          (social; AdvanceAiAllianceLifeAction)
 ├─ SessionDecisionService::run
 │    └─ DecisionEngine: one CATEGORY (Build, Raid#report, Transfer, ...)          ← today's "decision"
 └─ ScheduleAiIntentAction::handle
      ├─ GoalBoard hold                                (which colony rung the account works toward)
      ├─ unit planner: wall orders for bare planets    ← WHICH defence, HOW MANY, WHERE
      ├─ threat responses                              ← save / evacuate / wall
      ├─ fillQueues: one building per planet + 1 tech  ← WHICH building/technology (pass order)
      ├─ errand arm (match on category)                ← parameters chosen by that category's planner
      ├─ capital fleet order                           ← WHICH hull (doctrine fleet template)
      └─ runManagers: raid waves, missiles, probes,    ← WHICH targets, how many, in which priority
         transfer, expedition, colony, recycle, RaidWave
```

Consequence: replacing `UtilityScorer` with a network would leave building, research, shipyard, target and
fleet choices exactly where they are. A learned policy has to sit at the **planner choice points**. See
[state-and-action-space.md](state-and-action-space.md).

## 5. Deterministic AI: what it can be for RL

| Role | Feasible? | Evidence / condition |
| --- | --- | --- |
| Baseline opponent | **Yes, today.** | It plays in `ai:sim`; 20 accounts × 10 simulated days ran here with 0 errors once `symfony/yaml` was present. |
| Training-data generator | **Yes, after small logging additions.** | Traces lack planner-level candidate sets and the observation. Additions specified in [training-plan.md](training-plan.md#3-minimum-logging-additions). |
| Imitation teacher | **Yes for planner choice points**; poor for the errand slot (constants + jitter). | The building planner's "first legal in pass order" is a deterministic function of host state, which is learnable. |
| Fallback policy | **Yes.** | The learned scorer only re-ranks a candidate list the planners already produce; returning the teacher's index is the fallback. |
| Benchmark | **Yes**, with care: personas, skill-band jitter and `idleOverride` make it noisy. Compare at fixed seeds. | `SeededRandomSource` hashes `(seed, context)`, so decisions replay. |

## 6. Coupling to social/cognitive machinery inside gameplay (what may be disabled)

| Seam | Default | Touches gameplay? | Safe to disable for v1 training? |
| --- | --- | --- | --- |
| `RunAiConversationCycleAction` | on | No (messages). | Yes. |
| `AdvanceAiAllianceLifeAction` | runs first session each minute + maintenance | Indirectly: alliance membership feeds `QueueableDefendPlanner` (ACS defend) and ally-gift guards. Cost: 211 ms and 820 queries per call [measured]. | Yes, **if** production also treats alliance state as absent for the learned policy, or alliances are frozen at episode start. |
| Affect term in `UtilityScorer` | weight **10** (`AiRuntimeSettings::affectDecisionWeight`) | Yes: ±10 points on Raid/FleetSave. | Set weight 0 **in both training and the production path that uses the learned policy**. Otherwise production decisions depend on a state the model never saw. |
| Experience bias in `EconomyUpgrades::rememberedBias` | weight **20** (`experienceDecisionWeight`) | Yes: ±20% of payback for an object. | Same rule: 0 in training and in the learned path, or include the bias as a feature. |
| FAtiMA / CBRKit / AgentOS / PsychSim sidecars | opt-in drivers; `ai:sim` uses them unless `--native-cognition` | Through the two weights above only. | Yes; `--native-cognition` already exists. |
| Campaign consultation (`ConsultCampaignDecisionAction`) | off | Can nudge ranking. | Yes. |
| Language lane | off for gameplay | No. | Yes. |

## 7. Things found along the way that matter for an RL build

1. **The module does not declare `symfony/yaml`** (used by `SessionDecisionService`, doctrine, behaviour
   files). It works only because the host's dev dependencies pull it in. A `composer install --no-dev`
   breaks every session with `Class "Symfony\Component\Yaml\Yaml" not found` [measured]. A training image
   built lean would hit this.
2. **Raid planning reads the report, not the live planet** (`app/Domain/Raid/ReportedPlanet.php`), so the
   omniscience flagged in the diagnosis is fixed for raids. Other planners still read host state of the
   account itself, which is legitimate.
3. **`PlanetServiceFactory::makeFromModel()` registers the report copy under the live planet's id**
   (HANDOFF section 6.1). In a long-lived simulation process this can leak a report copy into later reads.
   Must be fixed or scoped before a persistent in-process environment is trusted.
4. **Planners run up to three times per order** (eligibility in the factory, scheduling, execution). With
   ~25 ms per planner call [measured], this is the cheapest large speed-up available (diagnosis "step 1, one
   snapshot per login", still not started).
5. **Host RNG is not seedable everywhere**: expeditions, counter-espionage, moon destruction, NPC fleets use
   `random_int` (CSPRNG). Battles are only seeded on the estimator path. See [risks.md](risks.md#determinism).
