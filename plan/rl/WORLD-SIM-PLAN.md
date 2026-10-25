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
| Economy: buildings and research, two queues, logins | `src/economy.rs` | `ai:rl-catalogue`, `ai:rl-replay` | exact (0 stock gap, 0 time gap); units that move production are counted (W1)
| Ships and defence, shipyard queue | `src/units.rs` | `ai:rl-units-catalogue`, `ai:rl-units-replay` | exact on 3 universes, 3 600 orders |
| Distance, flight time, fuel, holding | `src/fleet.rs` | `ai:rl-fleet-settings`, `ai:rl-fleet-cases` | exact on 20 000/20 000 cases |
| Battles, repairs, loot, debris, moon chance | `src/combat.rs` | `ai:rl-battle-cases`, `ai:rl-combat-catalogue` | the host's own Rust engine is linked (not ported); settling is exact; ~82 µs a fight |
| World: many accounts on one clock, fleets as events, nine missions, several planets an account | `src/world.rs` | `ai:rl-mission-cases`, `ai:rl-more-mission-cases` | exact on every mission the host records (W3, W4, W6, W8): 0 differ over 3 200 cases |
| Scripted players: miner, raider, fleet saver; their sizing | `src/players.rs` | the world's own | 12 accounts x 10 days over 8 seeds; `players::tests` |
| Errand choice: what a login does with its fleet | `src/errands.rs`, `python.rs` (kind 2), `ogrl/train_world.py --queue 2` | none (candidates are the players' own sizing) | candidates, features (`STATE_NAMES`/`CAND_NAMES` + kind one-hot), exact masks, and the trainer: 2.5 M errand rows and 38 updates in a 2-minute smoke run |
| Python bindings for the economy (`VecEnv`) | `src/python.rs` | the economy's | used by `rl/ogrl/train_ppo.py`; the world binding is `VecWorld` (W5); errands are not bound yet |

Run everything with `cd rl/ogsim && cargo test --release` (28 tests; tests that need a catalogue under
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
- **Hardware.** Owner, 25 Oct: no budget below 90% of the machine (about 64 of 72 threads); run work in parallel, never pin cores (memory `cpu-cap-90`). Host PHP has no FFI,
  so PHP closed loops need `battle_engine=php` in the universe settings (memory `host-php-no-ffi`). On GPUs,
  `CUDA_DEVICE_ORDER=PCI_BUS_ID`: cuda:0 is the 3090 and cuda:1 is the 4070.
- **Test hygiene for world tests.** Storage caps stop accrual, so a conservation test zeroes `planet.rate` and
  compares with a quiet twin world.
- **The referee data lives in the app's storage, not the module's.** Every Rust test reads
  `<repo>/storage/rl/ogsim` (`../../../../storage` from `rl/ogsim`), and every file there comes from an artisan
  command: `ai:rl-catalogue` and `ai:rl-units-catalogue` and `ai:rl-units-replay` per universe, `ai:rl-fleet-settings`,
  `ai:rl-combat-catalogue`, `ai:rl-fleet-cases`, `ai:rl-battle-cases`, `ai:rl-mission-cases`. The read-only exports run
  in parallel; the four that draw cases take SQLite's write lock and must run one at a time, `ai:rl-mission-cases`
  alone. A missing file makes a test *skip*, which reads as green: check the count of cases a test reports, not just
  its name. `plain-eval.json` and the `-class2`/`-class3` fleet and combat catalogues have no documented producer; they
  were made by hand with `--player=<id>`.
- **A world can start at any second.** The mission cases start at unix time, so any "never" in the clock must be the
  true infinity and any per-account clock (the login times) must be seeded at the start: a login schedule that walks
  forward from second zero spends a million steps reaching 2026.
- **One catalogue, one character class.** The fleet settings and the combat tables bake in the class of the player they
  were exported for (fuel multiplier, loot share), so a fight between two classes needs one catalogue per side. The world
  now keeps one `Rules` per account (`World::rules`), which closes this.
- **The host stores a resource at 14 significant digits, not at full double precision.** Laravel binds a float as the
  string PHP's `precision` ini (14) produces, so every save rounds the value and every later read sees the rounded one.
  313.3333333333333 metal became 313.33333333333, and 0.75 of it then looted 234 instead of 235 — the last failing attack
  case. `economy::as_stored` reproduces the rounding; use it at **every** write of a stock or a cargo (accrual, dispatch,
  arrival, landing, loot) and nowhere else. Do not "fix" it by widening precision: the host is the referee.

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
| W1 | Units that change production (solar satellites, crawlers) counted by the economy, read from host tables | Rust test exact against host production on level patterns and unit counts its tables are not tabulated at (`economy::tests::composes_the_unit_tables_as_the_host_does`); the same test fails if no probe holds a unit | **done** `97b6854` |
| W2 | Host mission cases: `ai:rl-mission-cases {universe} {out}` for transport, deploy, espionage, attack and recycle through the host's real dispatch and processing, with writes rolled back | Pest `MissionCasesExportTest`; `storage/rl/ogsim/mission-cases-u1.json` (~2 000 cases) | **done** `fc4f464`: 2 000 cases, 1 751 accepted; every attack seed replays the host fight |
| W3 | World parity against W2: each case replayed in `world.rs` gives the host's times, fuel, stock, ships, debris and moon (battles: settle given the host's survivors, as `combat.rs` tests) | Rust test over every case | **done**: transports 171 + 20 refusals, attacks 357 + 30 refusals equal to the host (`OGSIM_MISSION=attack cargo test --release mission_parity`): one `Rules` per account (two classes), cargo in the hold, the survivors' loot cap, and the host's 14-digit stock rounding (`economy::as_stored`) |
| W4 | Espionage (probes, the report's sections, counter-espionage), recycle (debris, recycler capacity read from host data), and deploy as a mission rule, in `world.rs` | W3's cases for those missions, plus W3's attack gaps above | **done** (espionage 344 + 44 refusals, recycle 351 + 49 refusals equal to the host: `OGSIM_MISSION=espionage or recycle`); deploy skipped, its targets are second planets the catalogue lacks (W6) |
| W5 | Python binding `VecWorld`: N worlds of M accounts, `controlled` slots decide through the model (same features as `VecEnv`), the rest play the player rule; each decision gets a ticket and its reward (value change until that account decides again, plus the bonus at the end) comes back from `step` by ticket | smoke run plus measurement | **done** (economy decisions): 110 k account-hours/s and ~10 k controlled decisions/s per core, about 25 µs per decision, mostly the world itself, not Python. Fleet and shipyard decisions come with W7/W9. Note: `Game::value` counts buildings and stock, not ships, so a policy that buys ships needs ships at price in the value first |
| W6 | Colonisation and multiple planets per account: an account owns several economies, research is shared across them and done at one lab, and deploy works between own planets | W2 cases for colonise and deploy; research equal on every planet | **done**: planets are slots, research is the capital's and copied to the account's other planets, colonies build alone; colonise 218 + 82 refused and deploy 352 + 48 refused exact, all earlier missions still exact. A colony exists only for slots listed in `ai:rl-catalogue --sites=file.json` (default `econ-u1.json` has none; `econ-u1-colonies.json` has 123); `players.rs` still treats every slot as an account and must skip colony slots (`account_of(a) != a`) |
| W7 | Scripted human-like baselines on the world: a miner (today's player rule), a raider (spy inactives, raid with cargos, recycle), and a fleet saver (leaves on an incoming attack at a login) | Bands from `plan/rl/human-growth-check.md` and the external data (memory `external-ogame-data`) | **first version done** `players.rs`: Miner, Raider, FleetSaver read from host tables; 12 accounts x 10 days: 24 raids, loot 215 k weighted, saves 1 ok / 3 late (fails 1 of 8 seeds: no save ok); ~45 k account-hours/s. Gaps are listed in row W7b |
| W7b | World API the scripted players lack (needs W6 done first, same file `world.rs`): (1) an event log of fleet arrivals, battles and returns with cargo, so loot, losses and save outcomes are read, not inferred; (2) `Mission::Deploy` and a recall that turns a fleet home, so a saver can carry resources away and back; (3) logins for accounts with nothing left to build (today they get no decision, so a fully built late-game account can never save or raid); (4) an inactive flag per account (the 'i' status) for raid target choice; (5) public per-unit attack, hull and capacity accessors on `CombatCatalogue`; (6) a public `World::harvester` and a unit setter that syncs `planet.units` | players test passes on all 8 seeds with saves ok > 0; loot read from the log equals the stock change | **done**: event log (`world.events`), `recall`, opt-in idle logins (`enable_idle_logins`, `decision_kind`), `inactive`, combat accessors, `set_units`; players use all of it, the 8-seed test asserts over the seed set (seed 8 legitimately cannot save) |
| W8 | Moons, phalanx, jump gate, missiles, expeditions, ACS, moon destruction | one host case set each | **expedition, missile and moon destruction are flown by the world and exact** (252, 221, 265 cases, 0 differ); phalanx, jump gate, ACS, harvesting deep-space debris, attacks on a moon's own contents remain open |
| W9 | Self-play training on the world: one small model per decision (next-steps §1), initialised by cloning the scripted players, then RL with a KL anchor to the clone | next-steps gates 1–4; a PHP closed loop as the final check only | open, after W5 and W7 |
| W10 | Errand choice (spy / raid / recycle / save / order ships) as a new decision kind behind the same seam, BC from the scripted raider then PPO with a KL anchor; spec `errand-spec.md` | smoke, 20-minute run, value and human-likeness vs the scripted mix | **sim side done**: `errands.rs` offers `spy`, `raid`, `recycle`, `save`, `recall`, `order`, `wait` with derived features and masks that are exact by construction (`errands::tests::every_offered_errand_is_one_the_world_accepts`; `a_raid_errand_brings_loot_home`). Kinds are 7, not the spec\'s 6: a save is two decisions (leave, then bring it home). Rewards use `players::account_value`, which already counts ships at price and resources in flight, so `Game::value` is untouched. **Next**: `python.rs` kind 2 (`VecWorld` errand mode), then BC on the players\' labels and PPO with the KL anchor in `train_world.py` |
| W11 | Human-likeness measures (`rl/scripts/humanlike.py`, `humanlike-measures.md`): login pattern, reaction latency, save success rate, raid cadence, growth bands vs real data | table of which measures the scripted accounts fail | **measures exist** (`examples/dump_humanlike.rs` writes them as JSON: logins by hour, busiest week hours, gaps, raids, spies, loot, saves, recalls, planets, value). First finding: the accounts log in in **24 distinct hours of the day** (the host flags 18 in a week) and a shortest gap of 600 s, so they are not yet human on logins. `humanlike.py` (the comparison and the report) is not written yet; nearly every band it would compare against is a placeholder — only growth has real numbers (`human-growth-check.md:100-101`) |
| W12 | PHP choice point for errands so the served model decides in the real game; sim and PHP must agree on the same states | like `ogrl.econ.compare` | open, after W10 |

## 5. Agents in flight (Sonnet, 25 Oct; each owns separate files)

| Agent task | Row | Files it owns |
| --- | --- | --- |

When the last two finish, the lead integrates them into `world.rs` as missions (after W6 settles the planet model).
Agents commit nothing; the lead commits by file name. Check `git status` before starting anything.

## 6. Log (newest last)

| Date | Commit | What |
| --- | --- | --- |
| 25 Oct | `031e89b` | Simulator split into modules; `peek`/`tick`; world of independent accounts equal to the economy alone |
| 25 Oct | `56f2bac` | Fleet module exact on 20 000 cases |
| 25 Oct | `b9d1a84` | Combat module: host engine linked, settling exact |
| 25 Oct | `a95f0a9` | Units module exact on three universes |
| 25 Oct | `ef9ab6a` | World plays raids and transports; conservation tests |
| 25 Oct | `fc4f464` | Mission referee `ai:rl-mission-cases` |
| 25 Oct | `97b6854` | Units that move production are counted (W1); the world hands back half the fuel inside the cargo and replays the host's transports (W3); the clock's "never" became the true infinity, which fixed a spin on a world that starts at unix seconds |

## 7. Host behaviour that differs from real OGame (the simulator mirrors the host; the owner decides on host fixes)

- Half the fuel is added to the cargo of every mission (`FleetMissionService::getResources`), not only deployment;
  recycle is the exception. A transport delivers its deuterium plus fuel/2, and attack and espionage fleets carry
  fuel/2 home.
- The depth of an espionage report uses the probes sent, not those that survived. A report is written even when
  every probe dies.
- The defender's wreck field is created even when no ship was lost (41 of 357 attacks).
| 25 Oct | `7ce3e6e` | W4 done: espionage 344 and recycle 351 cases exact; attacks and transports still exact |
| 25 Oct | `6187ad5` | Second referee `ai:rl-more-mission-cases`: colonise, expedition, missile, moon destruction (1 200 cases) |
| 25 Oct | `995daf8` | W7 first version: scripted miner, raider, fleet saver (`players.rs`) |
| 25 Oct | `5cb28df` | Missile strikes and moon destruction exact |
| 25 Oct | (this commit) | Expedition exact on 252 cases; combat export carries `strike` and `expedition` sections |
| 25 Oct | (this commit) | W6 done: several planets per account, colonise and deploy exact |
| 25 Oct | (this commit) | W7b, W8 integration: events, recall, idle logins, inactive flag; expedition, missiles and moon destruction flown by the world, nine mission parities at 0 differ; players pass over 8 seeds |
| 25 Oct | (this commit) | W10 sim side: `errands.rs` (7 kinds, derived features, exact masks), built on the players\' own sizing (`raid_plan`, `harvest_plan`, `save_plan`, `order_plans`) so the teacher and the candidates cannot drift apart |
| 25 Oct | (this commit) | The other half of the same blocker: a queue that once found nothing legal slept for good (`free[q] = NEVER`), so 10 of 12 scripted accounts never researched in ten days — no lab yet at the first research login, and no second look when the lab arrived. Waking both queues when a build lands changes nothing in the host parity (`ScenarioReplayTest` 9/9) and everything in play: every account now researches (65-107 decisions), all four raiders hold 30-34 ships, spy 42-60 times and raid 4-15 times. The recycle candidate also became payable (its fuel is the dispatch's own refusal), which the errand mask test caught. |
| 25 Oct | (this commit) | A fleet exists at all: the scripted rule now mines a resource it neither makes nor holds (`short`) and builds the prerequisites of the ships it wants, up the chain and across both queues (`unlock`, host requirement lists only). Before this a fresh account reached 5.6 M value after ten days with **zero ships**, so no raid, spy or save could ever happen; now a raider spies and raids and a miner is raided (`examples/dump_humanlike.rs`). Only 1 of 4 raiders builds the fleet in 240 h, which is the next gap. |
| 25 Oct | (this commit) | W10 training side: `VecWorld(errands=True)` hands out kind 2 (errand rows on the same one-row-per-world stream, `wait` at row 0, padded to 32), rewards in `account_value` (ships at price and what flies); `train_world.py --queue 2` runs it: 2-minute smoke, 2.5 M errand rows, 38 updates, 120 k account-hours/s |
