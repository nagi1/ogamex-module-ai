# The whole game in the fast simulator: plan and handoff

Owner direction, 25 October 2026: OGame is equations in time plus persisted state, so the whole game runs in the
Rust simulator `rl/ogsim`. Training and evaluation happen there, and PHP is only the referee that checks the
simulator still matches the host. This file is the working plan. An agent picking up the work reads it top to
bottom, takes the first open row in §4, and updates §4 and §6 when it is done.

- Design and rules: [world-sim-spec.md](world-sim-spec.md).
- The earlier model plan (one small model per decision, its four gates, and results): [next-steps.md](next-steps.md).
- Binding for every row: `Modules/AI/AGENTS.md` (three gates) and `.github/skills/ogame-rl-training/SKILL.md`.

## 1. Where it stands (25 October 2026, after `ef9ab6a`)

| Part | File | Host referee | Status |
| --- | --- | --- | --- |
| Economy: buildings and research, two queues, logins | `src/economy.rs` | `ai:rl-catalogue`, `ai:rl-replay` | exact (0 stock gap, 0 time gap); the split into `peek`/`tick` gave identical games on 192 planets |
| Ships and defence, shipyard queue | `src/units.rs` | `ai:rl-units-catalogue`, `ai:rl-units-replay` | exact on 3 universes, 3 600 orders |
| Distance, flight time, fuel, holding | `src/fleet.rs` | `ai:rl-fleet-settings`, `ai:rl-fleet-cases` | exact on 20 000/20 000 cases |
| Battles, repairs, loot, debris, moon chance | `src/combat.rs` | `ai:rl-battle-cases`, `ai:rl-combat-catalogue` | the host's own Rust engine is linked (not ported); settling is exact; ~82 µs a fight |
| World: many accounts on one clock, fleets as events, attack, transport | `src/world.rs` | none yet (row W3) | tested: matches the economy alone; conservation of raid loot and transport cargo |
| Python bindings for the economy (`VecEnv`) | `src/python.rs` | the economy's | used by `rl/ogrl/train_ppo.py`; the world has no binding yet (row W5) |

Run everything with `cd rl/ogsim && cargo test --release` (12 tests; tests that need a catalogue under
`storage/rl/ogsim/` skip with a message when it is missing). Python build: `cargo build --release --features python`
or maturin, as `rl/README` and `train_ppo.py` expect.

## 2. Rules learned the hard way

- **Parity first.** A module is used for training only after it reproduces host answers. An integer the host
  rounds must match to the unit.
- **Diagnose a gap with a direct measurement before retraining** (memory `no-circles-diagnose-first`). The building
  model failed the closed loop four times because of the action space and timing, not the model.
- **Gate 1.** No object, unit or research is named in Rust. Everything comes from host exports. Mission type ids
  are the host's `GameMissionType` values (the `Mission` enum in `world.rs`). Whether a unit can fly is read from
  its speed (> 0).
- **Ownership.** One owner per file while work runs in parallel. Commit by file name only (`git add <files>`; never
  `-A`, `stash`, `reset`, `clean` or a force-push). When `AIServiceProvider.php` holds other agents' lines, stage
  your hunk alone (`git apply --cached` on a filtered diff).
- **Hardware.** Use about 26 processes on socket 0 and never pin cores (memory `cpu-cap-90`). Host PHP has no FFI,
  so PHP closed loops need `battle_engine=php` in the universe settings (memory `host-php-no-ffi`). On GPUs,
  `CUDA_DEVICE_ORDER=PCI_BUS_ID`: cuda:0 is the 3090 and cuda:1 is the 4070.
- **Test hygiene for world tests.** Storage caps stop accrual, so a conservation test zeroes `planet.rate` and
  compares with a quiet twin world.

## 3. Design of the world (what a new agent must know before touching `world.rs`)

- `World { games: Vec<Game>, next, rules: Option<Arc<Rules>>, coords, yards, fleets, debris, moons }`. There is one
  account per `Game`, with one planet per account until row W6.
- The clock: `next_decision(cat)` moves whatever comes first. That is a fleet event (`Fleet::next`, which goes first
  on a tie) or the account with the earliest `Catalogue::peek`, moved by one `Catalogue::tick`. It returns the
  account whose queue must decide (`games[a].queue`: 0 for buildings, 1 for research).
- At a decision the caller may call:
  - `dispatch(cat, a, target, Mission, ships, cargo, speed 0.5..10)` (refusals return `Err`);
  - `order_units(cat, a, unit index, amount)`;
  - and then exactly one of `commit(cat, a, k)` (debug-asserts `legal`, which includes the shipyard's upgrade
    block) or `wait(cat, a)`.
- Before a fleet touches a planet, `catch_up` brings that planet's stock and shipyard to the event second. This is
  safe because nothing of that planet is due earlier.
- `Rules::load(cat, units.json, fleet-settings.json, combat.json)`. The combat catalogue also serves speeds and fuel.
  Test data is in `storage/rl/ogsim/units-parity/{econ,units}-u1.json`, `fleet-settings-u1.json` and `combat-u1.json`.

## 4. Work rows (take the first open one; mark it done with the commit hash)

| Row | What | Proof | Status |
| --- | --- | --- | --- |
| W1 | Units that change production (solar satellites, crawlers) counted by the economy, read from host tables | Rust test exact against host production on random unit counts; games without such units bit-identical | **agent running** (owns the production code in `economy.rs`, `ExportAiEconomyCatalogueAction.php`, `EconomyCatalogueExportTest.php`) |
| W2 | Host mission cases: `ai:rl-mission-cases {universe} {out}` for transport, deploy, espionage, attack and recycle through the host's real dispatch and processing, with writes rolled back | Pest `MissionCasesExportTest`; `storage/rl/ogsim/mission-cases-u1.json` (~2 000 cases) | **done** `fc4f464`: 2 000 cases, 1 751 accepted; every attack seed replays the host fight |
| W3 | World parity against W2: each case replayed in `world.rs` gives the host's times, fuel, stock, ships, debris and moon (battles: settle given the host's survivors, as `combat.rs` tests) | Rust test over every case | **agent running** (owns `world.rs`, `rl/ogsim/tests/`) |
| W4 | Espionage (probes, the report's sections, counter-espionage) and recycle (debris, recycler capacity read from host data), and deploy as a mission rule, in `world.rs` | W3's cases for those missions | **agent running** with W3 |
| W5 | Python binding `VecWorld`: N worlds of M accounts, `controlled` slots decide through the model (same features as `VecEnv`), the rest play the player rule; each decision gets a ticket and its reward (value change until that account decides again, plus the bonus at the end) comes back from `step` by ticket | smoke run plus measurement | **done** (economy decisions): 110 k account-hours/s and ~10 k controlled decisions/s per core, about 25 µs per decision, mostly the world itself, not Python. Fleet and shipyard decisions come with W7/W9. Note: `Game::value` counts buildings and stock, not ships, so a policy that buys ships needs ships at price in the value first |
| W6 | Colonisation and multiple planets per account: an account owns several economies, research is shared across them and done at one lab, and deploy works between own planets | W2 cases for colonise and deploy; research equal on every planet | open |
| W7 | Scripted human-like baselines on the world: a miner (today's player rule), a raider (spy inactives, raid with cargos, recycle), and a fleet saver (leaves on an incoming attack at a login) | Bands from `plan/rl/human-growth-check.md` and the external data (memory `external-ogame-data`) | open |
| W8 | Moons, phalanx, jump gate, missiles, expeditions, ACS, moon destruction | one host case set each | open, after W7 |
| W9 | Self-play training on the world: one small model per decision (next-steps §1), initialised by cloning the scripted players, then RL with a KL anchor to the clone | next-steps gates 1–4; a PHP closed loop as the final check only | open, after W5 and W7 |

## 5. Agents in flight

| Agent task | Row | Files it owns | Started |
| --- | --- | --- | --- |
| Economy: satellites and crawlers | W1 | `economy.rs` (production code), `ExportAiEconomyCatalogueAction.php`, `EconomyCatalogueExportTest.php`, minimal edits to `units.rs` | 25 Oct |
| World parity plus espionage, recycle and deploy | W3, W4 | `world.rs`, `rl/ogsim/tests/` | 25 Oct |

Agents commit nothing; the lead commits by file name. If an agent's session is gone, re-run its row from the
description above. The work so far is in the working tree, so check `git status` first.

## 6. Log (newest last)

| Date | Commit | What |
| --- | --- | --- |
| 25 Oct | `031e89b` | Simulator split into modules; `peek`/`tick`; world of independent accounts equal to the economy alone |
| 25 Oct | `56f2bac` | Fleet module exact on 20 000 cases |
| 25 Oct | `b9d1a84` | Combat module: host engine linked, settling exact |
| 25 Oct | `a95f0a9` | Units module exact on three universes |
| 25 Oct | `ef9ab6a` | World plays raids and transports; conservation tests |
| 25 Oct | `fc4f464` | Mission referee `ai:rl-mission-cases` |

## 7. Host behaviour that differs from real OGame (the simulator mirrors the host; the owner decides on host fixes)

- Half the fuel is added to the cargo of every mission (`FleetMissionService::getResources`), not only deployment;
  recycle is the exception. A transport delivers its deuterium plus fuel/2, and attack and espionage fleets carry
  fuel/2 home.
- The depth of an espionage report uses the probes sent, not those that survived. A report is written even when
  every probe dies.
- The defender's wreck field is created even when no ship was lost (41 of 357 attacks).
