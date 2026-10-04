# State and action space

## 1. Recommendation in one paragraph

Use a **candidate-scoring policy** at the **planner choice points**, not a fixed action vector over the
engine's categories. At each choice point the existing planner code (host-derived, mod-friendly) produces a
list of legal candidates; the model scores every `(state, candidate)` pair with one shared network and a
softmax over the list chooses. The deterministic choice ("first legal candidate in pass order") is the
imitation label and the fallback. Object identity enters only as **host-derived numeric features** (cost,
production gain, payback, time, requirement depth, attack/shield/hull/cargo/speed), never as a hardcoded id
embedding, so a modded object is scorable on day one (Gate 1).

## 2. Why not the alternatives

| Representation | Fits our code? | Verdict |
| --- | --- | --- |
| Fixed categories (`AiCandidateActionType`, 19 values) then a second selector | The categories carry no parameters (`CandidateActionFactory.php:66-73`), and the categories are no longer where behaviour is decided (managers run regardless, `ScheduleAiIntentAction::runManagers`). | Learns the least valuable decision. Reject as the main space. |
| Fixed indexed space with masking (e.g. 62 objects × N planets + K targets) | Needs a stable index per object. The host catalogue is closed today (62 objects in `app/GameObjects/*Objects.php`, stored as planet columns), but Gate 1 forbids making ids the source of truth, and targets/planets are variable. | Workable with MaskablePPO, but breaks on any mod and wastes capacity on padding. Keep only as a fallback if candidate scoring fails to train. |
| **Candidate scoring** `(state, candidate) → score`, softmax over the legal list | The planners already produce ordered candidate lists (`QueueableBuildingPlanner::passes()`, `RaidPlanner` reports, spy targets, unit roles). | **Recommended.** Variable size, mod-friendly, teacher label available, host validation unchanged. Same family as DRRN (He et al. 2016, ACL) and pointer-style action heads (AlphaStar), and what action-embedding work recommends for large discrete spaces (Dulac-Arnold et al. 2015). |

Candidate scoring is not supported by stock SB3 `MaskablePPO`, which needs a fixed `Discrete(n)` space. The
workaround is a padded list of K candidate feature rows plus a mask (`Discrete(K)` + mask), where K is a
bound such as 32. That makes it SB3-compatible without giving up the scorer. See
[ml-stack-research.md](ml-stack-research.md#3-algorithm-fit).

## 3. Choice points (what one model decision is)

Ordered by value and by how cleanly the current code exposes a candidate list.

| # | Choice point | Candidate source today | Teacher label today | v1? |
| --- | --- | --- | --- | --- |
| C1 | **Build queue of planet P is free** | `QueueableBuildingPlanner::passes()` → `BuildCandidate`s (wall, doctrine opening, storage, surplus, routine: energy/facility chain/storage-for-price/production, ambition), filtered by `canQueue` | First queueable in pass order (`firstQueueable`) | **Yes** |
| C2 | **Lab is free** | Same passes, research candidates (`researchStep`, `researchDump`, chain prerequisites) | First queueable research | **Yes** |
| C3 | **Save instead of spend** | Add a synthetic candidate "WAIT/SAVE" to C1/C2 with features = ETA of the best unaffordable candidate. Today expressed by `SavingsGoal`/`canSpendFor` | When the planner returns null for the planet, label = WAIT | **Yes** |
| C4 | Shipyard order | `QueueableUnitPlanner` roles (wall, cargo for payload, colony ship, probe, capital fleet template hull) | The role the planner returned | v1.5 |
| C5 | Raid target selection (which reports, how many waves) | `RaidPlanner::plan` per report + doctrine `raid_waves` | Reports accepted, in trace order | v2 |
| C6 | Spy target batch | `QueueableSpyPlanner` | its order | v2 |
| C7 | Fleet save / stay / evacuate on inbound | `ThreatResponsePlanner`, `QueueableFleetSavePlanner` | planner output | v2 (safety-critical; keep rules first) |
| C8 | Colonise now / where | `QueueableColonyPlanner`, `GalaxyMap` | planner output | v2 |
| C9 | Session errand (today's `DecisionEngine`) | `CandidateActionFactory` | `UtilityScorer` | Not worth learning until features are real |

The first experiment uses C1+C2+C3 only: the economy and research decisions, which drive account value most
in the first weeks and whose candidate lists are richest. Everything else stays deterministic, so the account
still plays the whole game and the learned part is measured inside a complete player.

## 4. Candidate features (per candidate row, all host-derived)

| Group | Features | Source |
| --- | --- | --- |
| Identity-free kind | one-hot of `GameObjectType` (building, station, research, ship, defence), produces resource? (from `GameObjectProduction` non-null), has storage?, is requirement of something not yet owned? | `ObjectService` |
| Cost | metal, crystal, deuterium, energy of the next level (log1p, normalised by current hourly production) | `ObjectService::getObjectPrice` |
| Time | build/research time of next level in hours; affordability ETA in hours (0 if affordable) | `PlanetService::getBuildingConstructionTime`, planner ETA |
| Gain | production gain per hour of the next level (`EconomyUpgrades::productionGainOfNextLevel`), payback hours (`paybackHours`), energy delta, storage delta | module planners |
| Unlock value | count of objects this unlocks (techtree `RequiredBy`), depth to the doctrine's next goal | `TechtreeRequiredBy`, `ArchetypeDoctrine` |
| Context of origin | which pass produced it (wall/doctrine/storage/surplus/routine/ambition), the teacher's rank in its pass | planner (the `reason` string family) |
| Levels | current level, level after | planet |

No machine names, no object ids. A modded mine is a building with a production formula, a cost and a time,
and gets a score.

## 5. State features (shared context, per choice point)

Built once per login (this also removes the planners' repeated reads; see the diagnosis "step 1").

| Group | Features | Count (approx.) |
| --- | --- | --- |
| Time/session | simulated day of episode (log), universe economy/fleet/research speed, phase (`GamePhaseMachine`: early/mid/late), minutes to next planned login | 8 |
| Account | general/economy/research/military score (log), rank percentile, Δscore 24 h, planets owned / max (astrophysics), fleet slots used/total | 12 |
| Current planet | metal/crystal/deut stock (log), stock/storage ratio ×3, production/h ×3, energy produced/used, production factor, temperature, fields used/max, position bonus, build queue busy (h remaining), shipyard busy | 22 |
| Empire pooled | sum/mean/max over planets of stock, production, fields free; count of planets with idle queue | 15 |
| Research | levels of the account's researched technologies as a **set**: mean-pooled `[level, cost of next, type features]` per tech (identity-free), plus count | 8 |
| Fleet/defence | total ship value, military value, cargo capacity, defence value per planet (pooled), probes owned | 10 |
| Threat | inbound hostile count, minutes to first impact, `GalaxyMap::threat` of home system | 5 |
| Persona | archetype one-hot (5), skill band, activity band, defence doctrine, stockpile strategy | 12 |
| Recent outcomes | resources lost/gained by combat 24 h (log), raids sent/profit 24 h | 6 |
| **Total** | | **~100** |

Variable sets (planets, technologies, targets) are **pooled** (sum/mean/max) for v1. Attention over sets is
not needed until targets enter the action space (C5/C6), where a small set encoder over report rows is the
natural next step.

Normalisation: `log1p` for every resource or score quantity, then running mean/variance normalisation
(SB3 `VecNormalize` or our own) frozen at evaluation. Ratios stay in [0, 1]. Booleans as 0/1.

## 6. Perfect information

Every feature above is something the player can see on their own account pages, the highscore page or their
own reports. Do not add other players' live state. The raid planner already reads reports, not live planets
(`app/Domain/Raid/ReportedPlanet.php`).

## 7. Model size (derived, not guessed)

Shared-trunk candidate scorer:

```
state (≈100) ─ MLP 256-256 ─┐
                             ├─ concat ─ MLP 256-128 ─ score (1)        per candidate
candidate (≈30) ─ MLP 128 ──┘
value head: state embedding ─ MLP 256 ─ V (1)
```

Parameters ≈ 100·256 + 256·256 + 30·128 + 384·256 + 256·128 + 256·256 + small ≈ **0.29 M**. Doubling widths
gives ≈ 1.1 M. The 0.5–5 M estimate in the brief is plausible as an upper range; **0.3–1 M is enough for
v1**, because the inputs are ~130 numbers and the per-step decision is a ranking of ≤ 32 rows.

| Quantity | Estimate | Basis |
| --- | --- | --- |
| File size (fp32) | 1.2–4.5 MB | params × 4 B |
| CPU inference, one decision with 32 candidates | ~0.05–0.3 ms in ONNX Runtime or PyTorch; ~0.5–2 ms through PHP FFI to ONNX Runtime including marshalling | ~10–40 MFLOP per decision; to be measured, gate in [benchmark-plan.md](benchmark-plan.md) |
| Training batch on RTX 3090 | Not the bottleneck: millions of samples/s for an MLP this size | the simulator produces < 10⁴ decisions/s |
