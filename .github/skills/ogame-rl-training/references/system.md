# System map

## Data flow

```
ai:rl-universe (CreateAiRlUniverse)          fresh seeded universe in a SQLite file: host migrations, 1 admin,
                                             N AI accounts via SeedAiTestUniverseAction, persona seeds from the universe seed
        │
ai:sim --in-memory --seed (SimulateAiTime)   copies the DB into SQLite :memory:, binds a seeded Random\Randomizer,
        │                                    virtual clock (SimulatedTime), jumps to the next due work / fleet / maintenance
        │
RunAiSessionAction → SessionDecisionService → ScheduleAiIntentAction::fillQueues
        │
DecideAiEconomyStepsAction                   teacher path = QueueableBuildingPlanner::steps() unchanged;
        │                                    else: choiceSets() → EconomyChoiceEncoder::point() → ChoicePolicy::choose()
        │                                          → ChoiceRecorder::record() → replace the step for that queue
        ▼
AiWorkItem (BuildFirstBuilding / QueueResearch) → ExecuteAiIntentAction → QueueAiBuildingAction / QueueAiResearchAction
        → host BuildingQueueService::add / ResearchQueueService::add (the host validates again)
```

Training side (`rl/`): `ogrl.data` (JSONL → arrays) → `ogrl.train_bc` (CandidateScorer) → `model.onnx` →
`ogrl.serve` (Unix socket) ← `SocketChoicePolicy` (PHP, one connection per process, teacher fallback).

## File index

| Path | Role |
| --- | --- |
| `app/Domain/Decision/QueueableBuildingPlanner.php` | `steps()` (teacher), `passes()`, `choiceSets()` (candidates per free queue, legal/teacherOk/spendable), `refusal($planet, $candidate, $reserveHours)` (null hours = host-only check) |
| `app/Actions/DecideAiEconomyStepsAction.php` | applies the policy, records, learner selection (`ai.rl.learner_share`) |
| `app/Domain/Choice/EconomyChoiceEncoder.php` | state (45) and candidate (27) features, `VERSION`, account value |
| `app/Domain/Choice/ChoicePoint.php`, `ChoiceCandidate.php` | value objects; row 0 = wait |
| `app/Contracts/ChoicePolicy.php` | `choose(ChoicePoint, AiProfile): int`, `name()` |
| `app/Domain/Choice/{Teacher,Epsilon,Socket}ChoicePolicy.php` | planner / seeded exploration / Python server |
| `app/Domain/Choice/ChoiceRecorder.php` | JSON Lines + `.schema.json` sidecar |
| `app/Console/Commands/SimulateAiTime.php` | `ai:sim` and every RL option |
| `app/Console/Commands/CreateAiRlUniverse.php` | `ai:rl-universe` |
| `config/rl.php` | `ai.rl.*` defaults (policy teacher, recorder off) |
| `tests/Feature/EconomyChoiceSeamTest.php` | teacher neutrality, recording, legality of exploration, socket fallback |
| `rl/ogrl/*.py`, `rl/scripts/*.sh` | training, serving, evaluation, data generation |
| `plan/rl/*` | design, measurements, gates, roadmap |
| host `app/Providers/AppServiceProvider.php` | binds `Random\Randomizer` (seedable game randomness) |
| host `app/GameMissions/BattleEngine/BattleEngine.php` | live battles draw their seed from the Randomizer |

## Recorded row

```json
{"v":1,"t":1791158400,"seed":7,"player":12,"planet":31,"archetype":"Miner","kind":"building",
 "state":[45 floats],"cands":[[27 floats] x K],"legal":[K bools],"objects":[null, 3, 14, ...],
 "teacher":1,"chosen":1,"policy":"teacher","learner":true,"value":5256.07}
```

`objects` is for analysis only (which host object a row was) and must never become a model input. `value` is
invested (host score formulas × 1000) + held resources of the account at that moment.

## Speed (measured on a 4-vCPU cloud VM, 4 Oct 2026)

Early game ×1,100–2,900 real time per universe, mid-game ×330; universes scale linearly over processes.
Recording adds ~10%. One login costs ~100–250 ms of PHP; the model costs ~70 µs per choice. If throughput
matters, see `plan/rl/benchmark-plan.md` and the gates there; do not start a Rust simulator on a hunch.
