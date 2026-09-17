# Half-wired play loops — research pass (17 September 2026)

Research-only pass. No code, tests, or task DB touched. Evidence class: **code-read** (file:line).
Loops already owned by the task DB (`IMPL-022/024/025/035/036/037/038/040/041/042`, `IMPL-021`) and
wave-6 gap rows `W6-1..6` are **not** re-listed as new; they are named only where a new loop touches
them.

---

## Section 1 — Half-wired play loops (new)

### HL-1 — `SaveResources` capability is declared, scored and scheduled but never produced

- **(a) loop** — "save resources" intent.
- **(b) symptom** — `app/Enums/AiCapability.php:8` declares `SaveResources = 'save_resources'` and
  maps it to `AiCandidateActionType::SaveResources` (`:18`); three policies weight it
  (`Policies/MinerPolicy.php:13` = 0.8, `Policies/TraderPolicy.php:11` = 1.0,
  `Policies/TurtlePolicy.php:13` = 0.8); `Actions/ScheduleAiIntentAction.php:135` maps
  `AiCandidateActionType::SaveResources => null`. But `PlayerObservationService::availableActions()`
  (`:331-337`) publishes only `Build/Research/QueueUnits/Colonize/Spy`, so no `SaveResources`
  candidate is ever built in `CandidateActionFactory`, the `null` executor branch is unreachable,
  and the three archetype preference weights are inert.
- **(c) missing closing step** — the capability is published and the selected intent is executed
  (or the whole dead capability is deleted).
- **(d) gate** — gate 2 (simplicity: a dead enum case + dead branch + three dead weights) and gate 3
  (a player "saving" must be nameable, e.g. hoarding for a specific next step, not a null intent).
- **(e) fix** — either delete the `SaveResources` enum case, the policy weights and the `=> null`
  branch, or publish it only when a real hoard goal exists and execute it as a deferred spend.

### HL-2 — `recovery` score term is always 0 on the live path

- **(a) loop** — "recover from a setback" utility.
- **(b) symptom** — `UtilityScorer.php:23` `RECOVERY_WEIGHT = 20.0`, consumed at `:56`
  `'recovery' => $features['recovery'] * self::RECOVERY_WEIGHT`. The feature is fed from
  `PerceptionSnapshot::recoveryFactor` (`:27`), which `PlayerPerceptionBuilder.php:41` builds as
  `max(0, min(1, (float)($observation['recovery_factor'] ?? 0)))`. `PlayerObservationService::ownedState()`
  (`:88-108`) never emits a `recovery_factor` key, so the live value is always `0.0`. It is non-zero
  only in replay (`Actions/ReplayAiScenarioAction.php:127`).
- **(c) missing closing step** — publish a real recovery signal (e.g. fraction of recent losses still
  unrebuilt) or delete the term and the field.
- **(d) gate** — gate 2 (a 20-point weight multiplying a constant zero is dead machinery).
- **(e) fix** — publish `recovery_factor` from an existing signal or delete the component, the
  `RECOVERY_WEIGHT` constant and the `recoveryFactor` snapshot field.

### HL-3 — `attack_permitted` is hardcoded `true`, so the `AttackNotPermitted` rejection is dead

- **(a) loop** — "only attack legal targets".
- **(b) symptom** — `PlayerObservationService.php:244` `'attack_permitted' => true,` (comment claims
  bashing/legality are re-checked at decision time). `CandidateActionFactory.php:217` consumes it —
  `if (!$report['attack_permitted']) … AttackNotPermitted` — which can never fire.
  `RaidPlanner::plan()` re-checks bashing and profit only; it never checks target legality (own
  planet, banned/vacationing/admin defender).
- **(c) missing closing step** — a legality check somewhere in the chain (report, planner or both).
- **(d) gate** — gate 3 (a human does not attack its own planets or protected players).
- **(e) fix** — compute `attack_permitted` from the host's own legality answer at report time, or
  add the check to `RaidPlanner::plan()` and stop publishing a constant `true`.

### HL-4 — `losingRuns` is counted and carried but never consumed

- **(a) loop** — "reject raids that mostly lose".
- **(b) symptom** — `NativeRaidEstimator.php:85` computes
  `losingRuns = count(array_filter(… $net < 0.0))`; `RaidEstimate.php:19` carries it; the class
  doc says it exists "so the estimate carries … how many sampled runs lost money". No caller reads
  it: `RaidPlanner.php:124` gates on `samples === 0 || p20NetProfit <= 0.0` only.
- **(c) missing closing step** — a gate that uses the losing-run count, or removal of the field.
- **(d) gate** — gate 2 (computed-but-unconsumed value).
- **(e) fix** — add a losing-run threshold to the profit gate (e.g. refuse when a majority of the
  screen loses) or delete `losingRuns` from `RaidEstimate`.

### Overlaps with already-tracked work (not re-listed as new gaps)

- **Activity risk is read but not scored** — `PlayerObservationService.php:240` publishes
  `'activity'`, and `ActivityIntelReader::activityProbabilityAtEta()` (`:57`) is never called; the
  raid candidate features (`CandidateActionFactory.php:256`) use only `confidence` + `travel_cost`.
  Activity is re-checked at dispatch (`QueueAiRaidAction.php:59`). This is the W6-1 / RAID-004
  surface, not a new gap.
- **Debris is assumed but never collected** — see Section 2; this is W6-5.

---

## Section 2 — RaidPlanner deep review

Full flow traced: `PlayerObservationService::targetReports()` → `CandidateActionFactory::raidCandidatesFromVisibleReports()`
→ `RaidPlanner::plan()` → `ScheduleAiIntentAction::scheduleRaid()` → `ExecuteAiIntentAction::raid()`
→ `QueueAiRaidAction::handle()`.

### Hardcoded constants

| Where | Constant | Note |
| --- | --- | --- |
| `RaidPlanner.php:33` | `BASHING_LIMIT = 6` | mirrors the OGame 6/day/planet rule; host `FleetController.php:469` only ever reports `bashingSystemLimitReached => false` — **no host source for 6**, so a universe that tunes it diverges (gate 1) |
| `RaidPlanner.php:35` | `BASHING_WINDOW_HOURS = 24` | same gate-1 exposure as above |
| `RaidPlanner.php:38` | `LOOT_TIER_FARM = 3.0` | sourced to RAID-011; fine |
| `RaidPlanner.php:40` | `LOOT_TIER_DEFENDED = 2.0` | justified "because the debris subsidises it" — **but debris is never collected** (below) |
| `RaidPlanner.php:43` | `CRYSTAL_WEIGHT = 1.5` | duplicates `NativeRaidEstimator.php:28`; two authorities for the same value |
| `RaidPlanner.php:45` | `DEUTERIUM_WEIGHT = 2.0` | duplicates `NativeRaidEstimator.php:29` |
| `RaidPlanner.php:48` | `RAID_STORAGE_FILL_RATIO = 0.8` | persona flavour, documented |
| `NativeRaidEstimator.php:26` | `SCREEN_SAMPLES = 50` | documented bounded screen |
| `PlayerObservationService.php:47` | `INTEL_TTL_HOURS = 24` | fine |
| `PlayerObservationService.php:50` | `VIABILITY_SCORE_DIVISOR = 5` | RAID-008, sourced |
| `ActivityIntelReader.php:19` | `ACTIVITY_WINDOW_MINUTES = 15` | host's public star |
| `ActivityIntelReader.php:76-77` | fast/slow `6:1` ratio | self-labelled **placeholder** ("not a measured constant") |

### Defects

1. **Debris value is never collected.** The defended tier (`LOOT_TIER_DEFENDED`) is loosened on the
   premise that debris subsidises the run, yet `DebrisFieldService`/`RecycleMission` (both present in
   the host — `app/Services/DebrisFieldService.php`, `app/GameMissions/RecycleMission.php`, mission
   type 8) are referenced **nowhere** in the module (`grep DebrisFieldService|RecycleMission app/`
   returns only the `RaidPlanner.php:194` comment). The subsidy is an unfunded assumption — W6-5.
2. **No post-dispatch feedback loop.** After `QueueAiRaidAction` queues the attack there is no
   comparison of the estimated P20 profit against the actual outcome; battle reports feed affect
   (`AppraiseObservedBattleReportAction`), not raid re-planning. The account never learns whether its
   raids were profitable.
3. **`attack_permitted` is always `true`** (`PlayerObservationService.php:244`) and
   `RaidPlanner::plan()` never checks target legality — see HL-3.
4. **`losingRuns` is dead** — see HL-4.
5. **`storageReady()` defaults to `true`** when the user is absent or the fleet origin is null
   (`RaidPlanner.php:68`, `:74`). Benign today only because `plan()` re-derives `origin` and returns
   null, but the default is the wrong polarity for the method's contract.
6. **Zero-fuel raids trivially clear the tier.** `clearsLootTier()` divides by `max(1, $fuel)`
   (`RaidPlanner.php:207`); an in-system raid quoted at 0 deuterium passes any tier.
7. **Duplicate metal-equivalent weights.** `CRYSTAL_WEIGHT`/`DEUTERIUM_WEIGHT` live in both
   `RaidPlanner` and `NativeRaidEstimator` — two authorities that can drift (gate 2).
8. **W6-1 evidence is stale.** The gap register still says `confidence = 1.0` and `travel_cost = 0.0`
   are placeholders; the current code computes both (`PlayerObservationService.php:233-241`
   `intelConfidence`, `:294-308` `travelCost`). The reader shipped; the register row should be
   re-checked, not assumed open.

### Highest impact to fix first

1. **Debris never collected** — real fleeter income, and it makes the `LOOT_TIER_DEFENDED` subsidy
   honest. Needs a new executor (W6-5 / F-series), largest effort.
2. **Target-legality gate** (HL-3) + **`losingRuns` gate** (HL-4) — two one-line-ish fixes that close
   a gate hole and a dead value.
3. **Hardcoded bashing limit** (gate 1) — move to a host-read value or document the host's own
   enforcement as the authority so the module stops carrying a policy constant.

---

## Section 3 — Gap register entries (Wave 9)

| # | Gap | Signal it weakens | Evidence | Closing it needs |
| --- | --- | --- | --- | --- |
| W9-1 | **`SaveResources` is a declared capability that nothing produces.** The enum case, three policy preference weights and the scheduler's `=> null` branch all exist, but `availableActions()` never publishes it, so the candidate is never built and the branch is dead. | 3, 4 (breadth of play, self-similarity) | code-read; `AiCapability.php:8`, `PlayerObservationService.php:331-337`, `ScheduleAiIntentAction.php:135`, `Policies/MinerPolicy.php:13` | either wire a real hoard-for-next-step intent or delete the capability, the weights and the null branch — gate 2/3 |
| W9-2 | **The `recovery` score term is always 0.** `RECOVERY_WEIGHT = 20.0` multiplies a `recoveryFactor` that `ownedState()` never publishes, so live decisions score it 0; only replay carries a value. | 3, 4 (a player rebuilding after a setback is invisible) | code-read; `UtilityScorer.php:23,56`, `PlayerPerceptionBuilder.php:41`, `PlayerObservationService.php:88-108` | publish a real recovery signal from an existing observation, or delete the component and the snapshot field |
| W9-3 | **Target legality is never checked before a raid.** `attack_permitted` is hardcoded `true`, so the `AttackNotPermitted` rejection is unreachable and `RaidPlanner::plan()` gates on bashing + profit only. | 1, 3 (a player attacking its own/protected planets) | code-read; `PlayerObservationService.php:244`, `CandidateActionFactory.php:217`, `RaidPlanner.php` | compute `attack_permitted` from the host's legality answer, or add the check to `RaidPlanner::plan()` |
| W9-4 | **`losingRuns` is counted and never consumed.** The estimator computes how many sampled runs lose, but no gate reads it. | 3, 6 (a fleeter that keeps raiding into losses) | code-read; `NativeRaidEstimator.php:85`, `RaidEstimate.php:19`, `RaidPlanner.php:124` | add a losing-run threshold to the profit gate, or delete the field |
